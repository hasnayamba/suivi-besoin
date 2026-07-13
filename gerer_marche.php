<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET RÉCUPÉRATION DES DONNÉES ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$marche_id = $_GET['id'] ?? $_POST['marche_id'] ?? null;
if (!$marche_id) {
    header('Location: logisticien.php');
    exit();
}

// --- RÉCUPÉRATION DU MARCHÉ ---
$stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE id = ?");
$stmt_marche->execute([$marche_id]);
$marche = $stmt_marche->fetch();

if (!$marche) { 
    $_SESSION['error'] = "Marché introuvable.";
    header('Location: marches.php');
    exit(); 
}

// --- VÉRIFICATION DE CONTRÔLE ---
// Si le marché est lié à un AO ou finalisé, on verrouille les actions.
$is_ao_market = !empty($marche['ao_id']);
$is_locked = $is_ao_market || in_array($marche['statut'], ['Paiement Approuvé']);


// --- GESTION DES ACTIONS POST ---
// On bloque toutes les actions si le marché est verrouillé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    
    // -- LOGIQUE POUR AJOUTER UN NOUVEAU DOCUMENT --
    if (isset($_POST['submit_document'])) {
        $document_type = $_POST['document_type'] ?? '';
        
        if (!empty($document_type) && isset($_FILES['fichier_document']) && $_FILES['fichier_document']['error'] === UPLOAD_ERR_OK) {
            
            $file = $_FILES['fichier_document'];
            $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            $newFileName = strtoupper(str_replace(' ', '_', $document_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
            $destPath = __DIR__ . '/uploads/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $pdo->beginTransaction();
                try {
                    $doc_id = 'DOC_' . time() . rand(100, 999);
                    $sql_insert = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
                    $stmt = $pdo->prepare($sql_insert);
                    $stmt->execute([$doc_id, $marche_id, $document_type, $newFileName]);

                    if ($document_type === 'Facture') {
                        $stmt_update = $pdo->prepare("UPDATE marches SET statut = 'Facturé' WHERE id = ?");
                        $stmt_update->execute([$marche_id]);
                        
                        // Notifier le comptable
                        $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
                        $besoin_id = $marche['besoin_id'];
                        foreach ($comptables as $comptable_id) {
                            $message = "Un dossier (Achat Direct) " . htmlspecialchars($marche_id) . " est prêt pour validation.";
                            $lien = "dossier_validation.php?besoin_id=$besoin_id";
                            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
                        }
                    }
                    
                    $pdo->commit();
                    $_SESSION['success'] = "$document_type ajouté avec succès.";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "Erreur SQL: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Erreur lors de la sauvegarde du fichier.";
            }
        } else {
            $_SESSION['error'] = "Veuillez sélectionner un type de document et un fichier valides.";
        }
    }

    // -- LOGIQUE POUR MODIFIER UN DOCUMENT EXISTANT --
    elseif (isset($_POST['update_document'])) {
        $document_id = $_POST['document_id'];
        $fichier_actuel = $_POST['fichier_actuel'];
        
        if (isset($_FILES['nouveau_fichier']) && $_FILES['nouveau_fichier']['error'] === UPLOAD_ERR_OK) {
            $uploadFileDir = __DIR__ . '/uploads/';
            
            if (!empty($fichier_actuel) && file_exists($uploadFileDir . $fichier_actuel)) {
                unlink($uploadFileDir . $fichier_actuel);
            }

            $file = $_FILES['nouveau_fichier'];
            $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            $newFileName = 'DOC_UPDATED_' . $document_id . '_' . time() . '.' . $fileExtension;
            $destPath = $uploadFileDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                try {
                    $stmt = $pdo->prepare("UPDATE documents_commande SET fichier_joint = ? WHERE id = ?");
                    $stmt->execute([$newFileName, $document_id]);
                    $_SESSION['success'] = "Le document a été mis à jour avec succès.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Erreur SQL lors de la mise à jour: " . $e->getMessage();
                }
            } else {
                 $_SESSION['error'] = "Erreur lors de la sauvegarde du nouveau fichier.";
            }
        } else {
            $_SESSION['error'] = "Aucun nouveau fichier sélectionné ou erreur lors de l'envoi.";
        }
    }
    
    // -- LOGIQUE POUR RESOUMETTRE AU COMPTABLE --
    elseif (isset($_POST['resubmit_comptable'])) {
        $besoin_id = $_POST['besoin_id_hidden'];
        try {
            // On remet le statut à "Facturé" et on efface le motif de rejet
            $stmt = $pdo->prepare("UPDATE marches SET statut = 'Facturé', motif_rejet = NULL WHERE id = ?");
            $stmt->execute([$marche_id]);

            // Notifier le comptable
            $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($comptables as $comptable_id) {
                $message = "Le dossier (Achat Direct) " . htmlspecialchars($marche_id) . " a été corrigé et soumis à nouveau.";
                $lien = "dossier_validation.php?besoin_id=$besoin_id";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
            }
            $_SESSION['success'] = "Le dossier a été soumis à nouveau au comptable.";

        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur lors de la resoumission: " . $e->getMessage();
        }
    }
    
    header('Location: gerer_marche.php?id=' . $marche_id);
    exit();
}


// --- PRÉPARATION DE L'AFFICHAGE ---
$stmt_docs = $pdo->prepare("SELECT * FROM documents_commande WHERE marche_id = ? ORDER BY FIELD(type_document, 'PV', 'Bon de Commande', 'Bon de Livraison', 'Facture')");
$stmt_docs->execute([$marche_id]);
$documents = $stmt_docs->fetchAll();

// Logique pour déterminer le prochain document requis (uniquement pour Achat Direct)
$documents_existants = array_column($documents, 'type_document');
$prochain_document = null;

if (!$is_locked) {
    // Si c'est un Achat Direct (qui commence 'Facturé' ou 'Rejeté') ou un Contrat 'En cours'
    if ($marche['type_procedure'] === 'Achat Direct' || $marche['statut'] === 'En cours') {
        if (!in_array('Bon de Commande', $documents_existants)) $prochain_document = 'Bon de Commande';
        elseif (!in_array('Bon de Livraison', $documents_existants)) $prochain_document = 'Bon de Livraison';
        elseif (!in_array('Facture', $documents_existants)) $prochain_document = 'Facture';
    } 
    // Si c'est un Achat Direct rejeté, on permet de modifier la facture
    elseif ($marche['statut'] === 'Rejeté par Comptable') {
         if (!in_array('Facture', $documents_existants)) $prochain_document = 'Facture';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer le Marché</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>
    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Gérer le Marché (Achat Direct)</h2>
                    <p class="text-muted mb-0 small">Marché ID: <code><?= htmlspecialchars($marche['id']) ?></code></p>
                </div>
                <a href="marches.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour à la liste</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

            <!-- ALERTE DE VERROUILLAGE -->
            <?php if ($is_ao_market): ?>
            <div class="alert alert-warning d-flex align-items-center" role="alert">
                <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                <div>
                    <h5 class="alert-heading">Dossier d'Appel d'Offres</h5>
                    Ceci est un marché généré par un Appel d'Offres. Pour le gérer, veuillez utiliser le module "Appels d'offres" ou
                    <a href="gerer_appel_offre.php?ao_id=<?= htmlspecialchars($marche['ao_id']) ?>" class="alert-link">cliquer ici</a>.
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($marche['statut'] === 'Rejeté par Comptable' && !$is_ao_market): ?>
                <div class="alert alert-danger">
                    <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Dossier Rejeté par le Comptable</h5>
                    <p><strong>Motif :</strong> <?= htmlspecialchars($marche['motif_rejet']) ?></p>
                    <hr>
                    <p class="mb-0">Veuillez corriger les documents (en utilisant le bouton <i class="bi bi-pencil"></i>) puis soumettez à nouveau.</p>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Détails du Marché</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><strong>Titre:</strong> <?= htmlspecialchars($marche['titre']) ?></li>
                                <li class="list-group-item"><strong>Fournisseur:</strong> <?= htmlspecialchars($marche['fournisseur']) ?></li>
                                <li class="list-group-item"><strong>Montant:</strong> <?= number_format($marche['montant'], 0, ',', ' ') . ' cfa' ?></li>
                                <li class="list-group-item"><strong>Statut:</strong> <span class="badge bg-primary"><?= htmlspecialchars($marche['statut']) ?></span></li>
                                <li class="list-group-item"><strong>Procédure:</strong> <?= htmlspecialchars($marche['type_procedure']) ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card">
                         <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Documents du Marché</h5>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDocumentModal" 
                                <?= ($prochain_document === null || $is_locked || $marche['statut'] === 'Rejeté par Comptable') ? 'disabled' : '' ?>>
                                <i class="bi bi-plus-circle me-1"></i> 
                                Ajouter <?= $prochain_document ? htmlspecialchars($prochain_document) : '...' ?>
                            </button>
                        </div>
                        <div class="card-body">
                            <table class="table">
                                <thead><tr><th>Type</th><th>Fichier</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">Aucun document ajouté.</td></tr>
                                <?php else: foreach ($documents as $doc): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($doc['type_document']) ?></strong></td>
                                        <td><a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" download><?= htmlspecialchars($doc['fichier_joint']) ?></a></td>
                                        <td class="text-end">
                                            <!-- Bouton Modifier désactivé si le marché est verrouillé (payé ou AO) -->
                                            <?php if (!$is_locked): ?>
                                            <button class="btn btn-sm btn-outline-secondary edit-doc-btn"
                                                    data-bs-toggle="modal" data-bs-target="#editDocumentModal"
                                                    data-doc-id="<?= $doc['id'] ?>"
                                                    data-doc-type="<?= htmlspecialchars($doc['type_document']) ?>"
                                                    data-doc-file="<?= htmlspecialchars($doc['fichier_joint']) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                         <!-- Bouton de resoumission (désactivé si c'est un AO) -->
                         <?php if ($marche['statut'] === 'Rejeté par Comptable'): ?>
                            <div class="card-footer text-center">
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id_hidden" value="<?= htmlspecialchars($marche['besoin_id']) ?>">
                                    <button type="submit" name="resubmit_comptable" class="btn btn-warning btn-lg" <?= $is_ao_market ? 'disabled' : '' ?>>
                                        <i class="bi bi-send-check me-1"></i> Soumettre à nouveau au Comptable
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
    
<!-- Modales (Ajouter et Modifier) -->
<div class="modal fade" id="addDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Ajouter : <?= htmlspecialchars($prochain_document ?? '') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                    <input type="hidden" name="document_type" value="<?= htmlspecialchars($prochain_document ?? '') ?>">
                    <div class="mb-3"><label for="fichier_document" class="form-label">Fichier à joindre <span class="text-danger">*</span></label><input class="form-control" type="file" name="fichier_document" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="submit_document" class="btn btn-primary">Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="editDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="editModalTitle">Modifier un document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="document_id" id="edit_document_id">
                <input type="hidden" name="fichier_actuel" id="edit_fichier_actuel">
                <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                <div class="modal-body">
                    <p>Fichier actuel: <strong id="edit_current_file_name"></strong></p>
                    <div class="mb-3"><label for="nouveau_fichier" class="form-label">Remplacer par un nouveau fichier</label><input class="form-control" type="file" name="nouveau_fichier" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="update_document" class="btn btn-primary">Mettre à jour</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script pour la modale de modification
    const editModal = document.getElementById('editDocumentModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const docId = button.getAttribute('data-doc-id');
            const docType = button.getAttribute('data-doc-type');
            const docFile = button.getAttribute('data-doc-file');

            editModal.querySelector('#editModalTitle').textContent = `Modifier le ${docType}`;
            editModal.querySelector('#edit_document_id').value = docId;
            editModal.querySelector('#edit_fichier_actuel').value = docFile;
            editModal.querySelector('#edit_current_file_name').textContent = docFile;
        });
    }
</script>
</body>
</html>