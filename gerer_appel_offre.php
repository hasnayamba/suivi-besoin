<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$ao_id = $_GET['ao_id'] ?? $_POST['ao_id'] ?? null;
if (!$ao_id) {
    $_SESSION['error'] = "ID d'appel d'offres manquant.";
    header('Location: suivi_ao.php');
    exit();
}

// --- FONCTION D'AIDE GLOBALE POUR L'UPLOAD ---
function handle_upload($file, $marche_id, $doc_type, $pdo) {
    try {
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
        $destPath = __DIR__ . '/uploads/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $doc_id = 'DOC_' . time() . rand(100, 999);
            $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);
            return $newFileName; // Retourne le nom du fichier
        }
    } catch (PDOException $e) {
        throw new Exception("Erreur BDD lors de l'upload: " . $e->getMessage());
    }
    throw new Exception("Erreur critique lors du déplacement du fichier.");
}

// --- TRAITEMENT DES DIFFÉRENTS FORMULAIRES POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        // --- Scénario 1 : Dépouillement (Création du marché) ---
        if (isset($_POST['submit_depouillement'])) {
            $besoin_id = $_POST['besoin_id'];
            $fournisseur = trim($_POST['fournisseur']);
            $montant = !empty($_POST['montant']) ? trim($_POST['montant']) : null;

            if (empty($fournisseur) || !isset($_FILES['pv']) || $_FILES['pv']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Le fournisseur et le PV sont obligatoires.");
            }

            $titre_besoin = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
            
            $marche_id = 'M' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
            // On lie le marché à l'AO via ao_id
            $sql_marche = "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id, ao_id, type_procedure) 
                           VALUES (?, ?, ?, ?, CURDATE(), 'Attribué', ?, ?, 'Appel d\'Offre')";
            $pdo->prepare($sql_marche)->execute([$marche_id, $titre_besoin, $fournisseur, $montant, $besoin_id, $ao_id]);

            $pdo->prepare("UPDATE besoins SET statut = 'Marché attribué' WHERE id = ?")->execute([$besoin_id]);
            $pdo->prepare("UPDATE appels_offre SET statut = 'Attribué' WHERE id = ?")->execute([$ao_id]);
            handle_upload($_FILES['pv'], $marche_id, 'PV', $pdo);
            
            $id_demandeur = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
            if ($id_demandeur) {
                 $message_demandeur = "Votre besoin '" . htmlspecialchars($titre_besoin) . "' a été attribué.";
                 $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, 'chef_projet.php')")->execute([$id_demandeur, $message_demandeur]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Marché attribué. Veuillez maintenant choisir le type de contrat.";
        
        // --- Scénario 2 : Choix de la contractualisation ---
        } elseif (isset($_POST['submit_choix_contrat'])) {
            $marche_id = $_POST['marche_id'];
            $type_contrat = $_POST['type_contrat']; // 'Bon de Commande' ou 'Contrat'
            
            // On met à jour le marché avec le type de contrat choisi et on passe le statut à "En cours"
            $sql = "UPDATE marches SET type_contrat = ?, statut = 'En cours' WHERE id = ?";
            $pdo->prepare($sql)->execute([$type_contrat, $marche_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Type de contrat '$type_contrat' enregistré. Vous pouvez maintenant joindre les documents de suivi.";

        // --- Scénario 3 : Ajout de documents (BC, BL, Facture, Contrat...) ---
        } elseif (isset($_POST['submit_document'])) {
            $marche_id = $_POST['marche_id'];
            $document_type = $_POST['document_type'];
            if (empty($document_type) || !isset($_FILES['fichier_document']) || $_FILES['fichier_document']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Type de document ou fichier manquant.");
            }

            handle_upload($_FILES['fichier_document'], $marche_id, $document_type, $pdo);
            
            $pdo->commit();
            $_SESSION['success'] = "$document_type ajouté avec succès.";

        // --- Scénario 4 : Envoi Manuel à la Comptabilité ---
        } elseif (isset($_POST['submit_to_comptable'])) {
            $marche_id = $_POST['marche_id'];
            $besoin_id = $_POST['besoin_id'];

            // On vérifie qu'au moins un document est joint (en plus du PV)
            $doc_count = $pdo->query("SELECT COUNT(*) FROM documents_commande WHERE marche_id = '$marche_id'")->fetchColumn();
            if ($doc_count <= 1) { // Si seul le PV est présent
                 throw new Exception("Impossible d'envoyer à la comptabilité. Vous devez joindre au moins un document (Contrat, BC, Facture...).");
            }

            $pdo->prepare("UPDATE marches SET statut = 'Facturé' WHERE id = ?")->execute([$marche_id]);
            $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?")->execute([$besoin_id]);

            $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($comptables as $comptable_id) {
                $message = "Dossier (AO) " . htmlspecialchars($marche_id) . " est prêt pour validation.";
                $lien = "dossier_validation.php?besoin_id=$besoin_id";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
            }

            $pdo->commit();
            $_SESSION['success'] = "Dossier complet envoyé à la comptabilité pour validation.";

        // --- Scénario 5 : Modification de document ---
        } elseif (isset($_POST['update_document'])) {
            $document_id = $_POST['document_id'];
            $fichier_actuel = $_POST['fichier_actuel'];
            
            if (!isset($_FILES['nouveau_fichier']) || $_FILES['nouveau_fichier']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Aucun nouveau fichier n'a été téléversé.");
            }
            
            $uploadFileDir = __DIR__ . '/uploads/';
            if (!empty($fichier_actuel) && file_exists($uploadFileDir . $fichier_actuel)) {
                unlink($uploadFileDir . $fichier_actuel);
            }
            
            $file = $_FILES['nouveau_fichier'];
            $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            $newFileName = 'DOC_UPDATED_' . $document_id . '_' . time() . '.' . $fileExtension;
            $destPath = $uploadFileDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $stmt = $pdo->prepare("UPDATE documents_commande SET fichier_joint = ? WHERE id = ?");
                $stmt->execute([$newFileName, $document_id]);
                $pdo->commit();
                $_SESSION['success'] = "Le document a été mis à jour.";
            } else {
                 throw new Exception("Erreur lors de la sauvegarde du nouveau fichier.");
            }
        
        // --- Scénario 6 : Resoumission après rejet ---
        } elseif (isset($_POST['resubmit_comptable'])) {
             $marche_id = $_POST['marche_id'];
             $besoin_id = $_POST['besoin_id'];
             
             $pdo->prepare("UPDATE marches SET statut = 'Facturé', motif_rejet = NULL WHERE id = ?")->execute([$marche_id]);
             $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?")->execute([$besoin_id]);

             $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
             foreach ($comptables as $comptable_id) {
                 $message = "Dossier (AO) " . htmlspecialchars($marche_id) . " a été corrigé et soumis à nouveau.";
                 $lien = "dossier_validation.php?besoin_id=$besoin_id";
                 $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
             }
             $pdo->commit();
             $_SESSION['success'] = "Dossier corrigé et soumis à nouveau au comptable.";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: gerer_appel_offre.php?ao_id=' . $ao_id);
    exit();
}


// --- LOGIQUE D'AFFICHAGE (GET) ---
$stmt_ao = $pdo->prepare("SELECT ao.*, b.titre AS besoin_titre, b.id AS besoin_id FROM appels_offre ao JOIN besoins b ON ao.besoin_id = b.id WHERE ao.id = ?");
$stmt_ao->execute([$ao_id]);
$ao = $stmt_ao->fetch();
if (!$ao) { header('Location: suivi_ao.php'); exit(); }

$stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE ao_id = ?");
$stmt_marche->execute([$ao_id]);
$marche = $stmt_marche->fetch();
$marche_id = $marche['id'] ?? null;

$documents = [];
if ($marche_id) {
    $stmt_docs = $pdo->prepare("SELECT * FROM documents_commande WHERE marche_id = ? ORDER BY FIELD(type_document, 'PV', 'Contrat', 'Bon de Commande', 'Bon de Livraison', 'Facture')");
    $stmt_docs->execute([$marche_id]);
    $documents = $stmt_docs->fetchAll();
}

$is_locked = ($marche && in_array($marche['statut'], ['Paiement Approuvé', 'Facturé']));
$documents_existants = array_column($documents, 'type_document');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer l'Appel d'Offres</title>
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
                    <h2 class="mb-1">Gérer l'Appel d'Offres</h2>
                    <p class="text-muted mb-0 small">Dossier: <?= htmlspecialchars($ao['besoin_titre']) ?> (<?= htmlspecialchars($ao['id']) ?>)</p>
                </div>
                <a href="suivi_ao.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

            <!-- =================================================================== -->
            <!-- SCÉNARIO 1: DÉPOUILLEMENT (Le marché n'existe pas encore)            -->
            <!-- =================================================================== -->
            <?php if (!$marche): ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0">Étape 1: Dépouillement (Choix du Gagnant)</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8 mb-3"><label for="fournisseur" class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label><input type="text" class="form-control" id="fournisseur" name="fournisseur" required></div>
                            <div class="col-md-4 mb-3"><label for="montant" class="form-label">Montant Final (Optionnel)</label><input type="number" class="form-control" id="montant" name="montant" placeholder="Montant en cfa"></div>
                        </div>
                        <div class="mb-3"><label for="pv" class="form-label">PV de dépouillement <span class="text-danger">*</span></label><input class="form-control" type="file" id="pv" name="pv" required></div>
                    </div>
                </div>
                <div class="text-center"><button type="submit" name="submit_depouillement" class="btn btn-success btn-lg">Valider le gagnant et Continuer</button></div>
            </form>
            
            <!-- =================================================================== -->
            <!-- SCÉNARIO 2: CONTRACTUALISATION (Marché 'Attribué') -->
            <!-- =================================================================== -->
            <?php elseif ($marche && $marche['statut'] === 'Attribué'): ?>
            <div class="card shadow-sm text-center">
                <div class="card-header"><h5 class="mb-0">Étape 2: Contractualisation</h5></div>
                <div class="card-body p-5">
                    <h3 class="card-title">Marché Attribué à <?= htmlspecialchars($marche['fournisseur']) ?>!</h3>
                    <p>Veuillez maintenant choisir le type de contractualisation pour ce marché :</p>
                    <form method="POST" class="d-inline-block me-2">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                        <input type="hidden" name="type_contrat" value="Bon de Commande">
                        <button type="submit" name="submit_choix_contrat" class="btn btn-primary btn-lg px-4"><i class="bi bi-receipt me-1"></i> Option 1: Bon de Commande</button>
                    </form>
                    <form method="POST" class="d-inline-block">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                        <input type="hidden" name="type_contrat" value="Contrat">
                        <button type="submit" name="submit_choix_contrat" class="btn btn-outline-secondary btn-lg px-4"><i class="bi bi-file-text me-1"></i> Option 2: Contrat Formel</button>
                    </form>
                </div>
            </div>

            <!-- =================================================================== -->
            <!-- SCÉNARIO 3: SUIVI (Marché 'En cours', 'Facturé', ou 'Rejeté') -->
            <!-- =================================================================== -->
            <?php elseif ($marche && in_array($marche['statut'], ['En cours', 'Facturé', 'Rejeté par Comptable', 'Paiement Approuvé'])): ?>
            
            <?php if ($marche['statut'] === 'Rejeté par Comptable' && !empty($marche['motif_rejet'])): ?>
                <div class="alert alert-danger"><h5 class="alert-heading">Dossier Rejeté</h5><p><strong>Motif :</strong> <?= htmlspecialchars($marche['motif_rejet']) ?></p></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Détails du Marché</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><strong>Fournisseur:</strong> <?= htmlspecialchars($marche['fournisseur']) ?></li>
                                <li class="list-group-item"><strong>Montant:</strong> <?= $marche['montant'] ? number_format($marche['montant'], 0, ',', ' ') . ' cfa' : 'N/A' ?></li>
                                <li class="list-group-item"><strong>Statut:</strong> <span class="badge bg-primary"><?= htmlspecialchars($marche['statut']) ?></span></li>
                                <!-- INDICE VISUEL -->
                                <li class="list-group-item"><strong>Type:</strong> <span class="badge bg-info"><?= htmlspecialchars($marche['type_contrat']) ?></span></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card">
                         <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Suivi des Documents</h5>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDocumentModal" <?= $is_locked ? 'disabled' : '' ?>>
                                <i class="bi bi-plus-circle me-1"></i> Ajouter un document
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
                                            <?php if (!$is_locked): ?>
                                            <button class="btn btn-sm btn-outline-secondary edit-doc-btn" data-bs-toggle="modal" data-bs-target="#editDocumentModal" data-doc-id="<?= $doc['id'] ?>" data-doc-type="<?= htmlspecialchars($doc['type_document']) ?>" data-doc-file="<?= htmlspecialchars($doc['fichier_joint']) ?>"><i class="bi bi-pencil"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- FOOTER AVEC BOUTONS D'ACTION MANUELS -->
                        <div class="card-footer text-center bg-light">
                            <?php if ($marche['statut'] === 'En cours'): ?>
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <p class="text-muted small">Envoyez le dossier à la comptabilité une fois que vous jugez qu'il est complet.</p>
                                    <button type="submit" name="submit_to_comptable" class="btn btn-success btn-lg" onclick="return confirm('Êtes-vous sûr de vouloir envoyer ce dossier au comptable ? Vous ne pourrez plus le modifier.')">
                                        <i class="bi bi-send-check me-1"></i> Envoyer à la Comptabilité
                                    </button>
                                </form>
                            <?php elseif ($marche['statut'] === 'Rejeté par Comptable'): ?>
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <button type="submit" name="resubmit_comptable" class="btn btn-warning btn-lg">Soumettre à nouveau au Comptable</button>
                                </form>
                            <?php elseif ($marche['statut'] === 'Facturé'): ?>
                                <div class="alert alert-info mb-0">Dossier en attente de validation par le comptable.</div>
                            <?php elseif ($marche['statut'] === 'Paiement Approuvé'): ?>
                                <div class="alert alert-success mb-0">Dossier finalisé et approuvé pour paiement.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modale pour Ajout flexible de document -->
<div class="modal fade" id="addDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Ajouter un document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id ?? '') ?>">
                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                    <div class="mb-3">
                        <label for="document_type" class="form-label">Type de document</label>
                        <select class="form-select" name="document_type" id="document_type" required>
                            <option value="">-- Choisir --</option>
                            <?php 
                            // Liste de tous les documents possibles dans ce flux
                            $types_possibles = ['Contrat', 'Bon de Commande', 'Bon de Livraison', 'Facture'];
                            
                            // On affiche que les types qui n'ont pas encore été ajoutés
                            foreach ($types_possibles as $type) {
                                if (!in_array($type, $documents_existants)) {
                                    echo "<option value=\"$type\">$type</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                     <div class="mb-3"><label for="fichier_document" class="form-label">Fichier à joindre <span class="text-danger">*</span></label><input class="form-control" type="file" name="fichier_document" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="submit_document" class="btn btn-primary">Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>
<!-- Modale pour Modifier un document -->
<div class="modal fade" id="editDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="editModalTitle">Modifier un document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="document_id" id="edit_document_id">
                <input type="hidden" name="fichier_actuel" id="edit_fichier_actuel">
                <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id ?? '') ?>">
                <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
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