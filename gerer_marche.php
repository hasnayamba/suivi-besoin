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
    header('Location: besoins_logisticien.php');
    exit();
}

// --- RÉCUPÉRATION DU MARCHÉ ---
$stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE id = ?");
$stmt_marche->execute([$marche_id]);
$marche = $stmt_marche->fetch();

if (!$marche) { 
    $_SESSION['error'] = "Dossier d'achat introuvable.";
    header('Location: besoins_logisticien.php');
    exit(); 
}

// --- VÉRIFICATION DE CONTRÔLE ---
$is_ao_market = !empty($marche['ao_id']);
// Si le paiement est approuvé, on verrouille pour empêcher de modifier les documents
$is_locked = $is_ao_market || in_array($marche['statut'], ['Paiement Approuvé']);


// --- GESTION DES ACTIONS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    
    // -- LOGIQUE POUR AJOUTER UN NOUVEAU DOCUMENT --
    if (isset($_POST['submit_document'])) {
        $document_type = $_POST['document_type'] ?? '';
        
        if (!empty($document_type) && isset($_FILES['fichier_document']) && $_FILES['fichier_document']['error'] === UPLOAD_ERR_OK) {
            
            $file = $_FILES['fichier_document'];
            $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            $newFileName = strtoupper(str_replace(' ', '_', $document_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
            
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $pdo->beginTransaction();
                try {
                    $doc_id = 'DOC_' . time() . rand(100, 999);
                    $sql_insert = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
                    $stmt = $pdo->prepare($sql_insert);
                    $stmt->execute([$doc_id, $marche_id, $document_type, $newFileName]);

                    // NOUVEAU : C'est l'ajout du Dossier Client qui déclenche la fin du processus
                    if ($document_type === 'Dossier Client') {
                        $stmt_update = $pdo->prepare("UPDATE marches SET statut = 'Facturé' WHERE id = ?");
                        $stmt_update->execute([$marche_id]);
                        
                        // Mettre à jour le besoin pour le tableau de bord
                        $stmt_besoin_update = $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?");
                        $stmt_besoin_update->execute([$marche['besoin_id']]);
                        
                        // Notifier la comptabilité
                        $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE LOWER(role) = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
                        $besoin_id = $marche['besoin_id'];
                        foreach ($comptables as $comptable_id) {
                            $message = "Un dossier complet (" . htmlspecialchars($marche['type_procedure']) . ") réf: " . htmlspecialchars($marche_id) . " est prêt pour paiement.";
                            $lien = "dossier_validation.php?besoin_id=$besoin_id";
                            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
                        }
                    }
                    
                    $pdo->commit();
                    $_SESSION['success'] = "Le document '$document_type' a été ajouté avec succès.";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "Erreur SQL: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Erreur lors de la sauvegarde du fichier sur le serveur.";
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
            
            // Supprimer l'ancien fichier
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
                    $_SESSION['success'] = "Le document a été remplacé avec succès.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Erreur lors de la mise à jour en base de données.";
                }
            } else {
                 $_SESSION['error'] = "Erreur lors de l'enregistrement du nouveau fichier.";
            }
        } else {
            $_SESSION['error'] = "Aucun nouveau fichier sélectionné.";
        }
    }
    
    // -- LOGIQUE POUR RESOUMETTRE À LA COMPTABILITÉ (Après un rejet) --
    elseif (isset($_POST['resubmit_comptable'])) {
        $besoin_id = $_POST['besoin_id_hidden'];
        try {
            $pdo->beginTransaction();
            // Remettre le marché et le besoin en statut 'Facturé' pour que le comptable le revoie
            $pdo->prepare("UPDATE marches SET statut = 'Facturé', motif_rejet = NULL WHERE id = ?")->execute([$marche_id]);
            $pdo->prepare("UPDATE besoins SET statut = 'Facturé', motif_rejet = NULL WHERE id = ?")->execute([$besoin_id]);

            $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE LOWER(role) = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($comptables as $comptable_id) {
                $message = "Le dossier " . htmlspecialchars($marche_id) . " a été corrigé par la logistique et soumis à nouveau.";
                $lien = "dossier_validation.php?besoin_id=$besoin_id";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
            }
            $pdo->commit();
            $_SESSION['success'] = "Le dossier a été renvoyé à la comptabilité.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors du renvoi : " . $e->getMessage();
        }
    }
    
    header('Location: gerer_marche.php?id=' . $marche_id);
    exit();
}


// --- PRÉPARATION DE L'AFFICHAGE ---
// Tri logique des documents
$stmt_docs = $pdo->prepare("SELECT * FROM documents_commande WHERE marche_id = ? ORDER BY FIELD(type_document, 'Proforma', 'Bon de Commande', 'Bon de Livraison', 'Facture', 'Dossier Client')");
$stmt_docs->execute([$marche_id]);
$documents = $stmt_docs->fetchAll();

// Logique pour déterminer le prochain document requis (Workflow guidé)
$documents_existants = array_column($documents, 'type_document');
$prochain_document = null;

if (!$is_locked) {
    if ($marche['type_procedure'] === 'Achat Direct' || $marche['statut'] === 'En cours' || $marche['statut'] === 'Facturé') {
        if (!in_array('Bon de Commande', $documents_existants)) {
            $prochain_document = 'Bon de Commande';
        } elseif (!in_array('Bon de Livraison', $documents_existants)) {
            $prochain_document = 'Bon de Livraison';
        } elseif (!in_array('Facture', $documents_existants)) {
            $prochain_document = 'Facture';
        } elseif (!in_array('Dossier Client', $documents_existants)) {
            $prochain_document = 'Dossier Client'; 
        } else {
            $prochain_document = 'Complet'; // Tout est fini
        }
    } 
    elseif ($marche['statut'] === 'Rejeté par Comptable') {
         $prochain_document = 'Complet'; // Pas d'ajout, juste de la modification
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi du Dossier d'Achat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div>
                <h3 class="h5 mb-0 fw-bold"><i class="bi bi-folder-symlink-fill text-primary me-2"></i>Gestion des pièces du marché</h3>
                <small class="text-muted">Réf : <code><?= htmlspecialchars($marche['id']) ?></code></small>
            </div>
            <a href="besoins_logisticien.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-2"></i>Retour</a>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

            <?php if ($is_ao_market): ?>
            <div class="alert alert-info d-flex align-items-center shadow-sm" role="alert">
                <i class="bi bi-megaphone-fill fs-4 me-3"></i>
                <div>
                    <h6 class="alert-heading fw-bold mb-1">Dossier issu d'un Appel d'Offres</h6>
                    Ce marché provient de l'AO Réf. <strong><?= htmlspecialchars($marche['ao_id']) ?></strong>.
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($marche['statut'] === 'Rejeté par Comptable'): ?>
                <div class="alert alert-danger shadow-sm border-danger">
                    <h5 class="alert-heading fw-bold"><i class="bi bi-x-circle-fill me-2"></i>Dossier Rejeté par la Comptabilité</h5>
                    <p class="mb-1"><strong>Motif du rejet :</strong> <?= htmlspecialchars($marche['motif_rejet'] ?? 'Non spécifié') ?></p>
                    <hr>
                    <p class="mb-0 small"><i class="bi bi-info-circle me-1"></i>Veuillez corriger le ou les documents problématiques à l'aide du bouton de modification (<i class="bi bi-pencil"></i>) puis soumettez le dossier à nouveau.</p>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white pb-0 border-0 pt-4 px-4">
                            <h5 class="fw-bold text-dark mb-0">Résumé de l'Achat</h5>
                        </div>
                        <div class="card-body p-4">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Titre du besoin</span>
                                    <strong class="text-dark"><?= htmlspecialchars($marche['titre']) ?></strong>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Fournisseur retenu</span>
                                    <span class="badge bg-light text-dark border border-secondary fs-6"><i class="bi bi-shop me-2"></i><?= htmlspecialchars($marche['fournisseur']) ?></span>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Montant Facturé</span>
                                    <span class="text-success fw-bold fs-5"><?= number_format($marche['montant'], 0, ',', ' ') ?> CFA</span>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Type de procédure</span>
                                    <strong class="text-primary"><?= htmlspecialchars($marche['type_procedure']) ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                         <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-files me-2 text-primary"></i>Pièces Justificatives</h5>
                            
                            <?php if ($prochain_document === 'Complet'): ?>
                                <span class="badge bg-success py-2 px-3"><i class="bi bi-check-all me-1"></i> Dossier Complet</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addDocumentModal" 
                                    <?= ($prochain_document === null || $is_locked || $marche['statut'] === 'Rejeté par Comptable') ? 'disabled' : '' ?>>
                                    <i class="bi bi-plus-lg me-1"></i> Joindre : <?= $prochain_document ? htmlspecialchars($prochain_document) : '...' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-4">Type de document</th>
                                        <th>Fichier</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-folder-x fs-1 d-block mb-2"></i>Aucun document ajouté.</td></tr>
                                <?php else: foreach ($documents as $doc): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark">
                                            <i class="bi bi-file-earmark-pdf text-danger me-2"></i><?= htmlspecialchars($doc['type_document']) ?>
                                        </td>
                                        <td>
                                            <a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" class="text-decoration-none small" target="_blank">
                                                <?= htmlspecialchars($doc['fichier_joint']) ?>
                                            </a>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" download class="btn btn-sm btn-outline-primary shadow-sm" title="Télécharger">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <?php if (!$is_locked): ?>
                                            <button class="btn btn-sm btn-warning fw-bold text-dark shadow-sm ms-1 edit-doc-btn"
                                                    data-bs-toggle="modal" data-bs-target="#editDocumentModal"
                                                    data-doc-id="<?= $doc['id'] ?>"
                                                    data-doc-type="<?= htmlspecialchars($doc['type_document']) ?>"
                                                    data-doc-file="<?= htmlspecialchars($doc['fichier_joint']) ?>" title="Remplacer le fichier">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($marche['statut'] === 'Rejeté par Comptable'): ?>
                            <div class="card-footer bg-white border-top text-end p-4">
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id_hidden" value="<?= htmlspecialchars($marche['besoin_id']) ?>">
                                    <button type="submit" name="resubmit_comptable" class="btn btn-success btn-lg fw-bold shadow" <?= $is_ao_market ? 'disabled' : '' ?>>
                                        <i class="bi bi-arrow-repeat me-2"></i> Renvoyer à la Comptabilité
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
    
<div class="modal fade" id="addDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-plus me-2"></i>Étape : <?= htmlspecialchars($prochain_document ?? '') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                    <input type="hidden" name="document_type" value="<?= htmlspecialchars($prochain_document ?? '') ?>">
                    
                    <p class="small text-muted mb-3">Veuillez joindre le document officiel correspondant à cette étape du processus d'achat.</p>
                    
                    <div class="mb-3 p-3 bg-white border rounded">
                        <label for="fichier_document" class="form-label fw-bold">Sélectionner le fichier (PDF/Image) <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" name="fichier_document" accept=".pdf,.jpg,.png,.jpeg" required>
                    </div>
                    
                    <?php if($prochain_document === 'Dossier Client'): ?>
                        <div class="alert alert-warning small border-warning text-dark mt-3">
                            <i class="bi bi-info-circle-fill me-1"></i> L'ajout de ce dernier document clôturera la préparation et alertera automatiquement la comptabilité pour paiement.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer bg-white border-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="submit_document" class="btn btn-primary fw-bold px-4">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark border-0">
                <h5 class="modal-title fw-bold" id="editModalTitle"><i class="bi bi-pencil-square me-2"></i>Remplacer un document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="document_id" id="edit_document_id">
                <input type="hidden" name="fichier_actuel" id="edit_fichier_actuel">
                <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <span class="d-block small text-muted text-uppercase mb-1">Fichier actuellement enregistré :</span>
                        <strong id="edit_current_file_name" class="text-danger"></strong>
                    </div>
                    <div class="p-3 bg-white border rounded mt-4">
                        <label for="nouveau_fichier" class="form-label fw-bold">Uploader le nouveau fichier corrigé <span class="text-danger">*</span></label>
                        <input class="form-control border-warning" type="file" name="nouveau_fichier" accept=".pdf,.jpg,.png,.jpeg" required>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="update_document" class="btn btn-warning fw-bold text-dark px-4">Mettre à jour le fichier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const editModal = document.getElementById('editDocumentModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const docId = button.getAttribute('data-doc-id');
            const docType = button.getAttribute('data-doc-type');
            const docFile = button.getAttribute('data-doc-file');

            editModal.querySelector('#editModalTitle').innerHTML = `<i class="bi bi-pencil-square me-2"></i>Remplacer : ${docType}`;
            editModal.querySelector('#edit_document_id').value = docId;
            editModal.querySelector('#edit_fichier_actuel').value = docFile;
            editModal.querySelector('#edit_current_file_name').textContent = docFile;
        });
    }
</script>
</body>
</html>