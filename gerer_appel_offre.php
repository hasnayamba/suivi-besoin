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
    header('Location: besoins_logisticien.php');
    exit();
}

// --- FONCTION  GLOBALE POUR L'UPLOAD ---
function handle_upload($file, $marche_id, $doc_type, $pdo) {
    try {
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
        
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); 
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $doc_id = 'DOC_' . time() . rand(100, 999);
            $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);
            return $newFileName; 
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
                throw new Exception("Le fournisseur et le PV de dépouillement sont obligatoires.");
            }

            $titre_besoin = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
            
            $marche_id = 'M' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
            
            $sql_marche = "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id, ao_id, type_procedure) 
                           VALUES (?, ?, ?, ?, CURDATE(), 'Attribué', ?, ?, 'Appel d\'Offre')";
            $pdo->prepare($sql_marche)->execute([$marche_id, $titre_besoin, $fournisseur, $montant, $besoin_id, $ao_id]);

            $pdo->prepare("UPDATE besoins SET statut = 'Marché attribué' WHERE id = ?")->execute([$besoin_id]);
            $pdo->prepare("UPDATE appels_offre SET statut = 'Attribué' WHERE id = ?")->execute([$ao_id]);
            
            handle_upload($_FILES['pv'], $marche_id, 'PV', $pdo);
            
            $id_demandeur = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
            if ($id_demandeur) {
                 $message_demandeur = "Bonne nouvelle : Votre besoin '" . htmlspecialchars($titre_besoin) . "' a été attribué après Appel d'Offres.";
                 $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, 'chef_projet.php')")->execute([$id_demandeur, $message_demandeur]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Marché attribué avec succès. Veuillez maintenant choisir le type de contrat pour poursuivre.";
        
        // --- Scénario 2 : Choix de la contractualisation ---
        } elseif (isset($_POST['submit_choix_contrat'])) {
            $marche_id = $_POST['marche_id'];
            $type_contrat = $_POST['type_contrat']; 
            
            $sql = "UPDATE marches SET type_contrat = ?, statut = 'En cours' WHERE id = ?";
            $pdo->prepare($sql)->execute([$type_contrat, $marche_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Type de contractualisation '$type_contrat' défini. Vous pouvez ajouter les documents de suivi.";

        // --- Scénario 3 : Ajout de documents (BC, BL, Facture, Contrat...) ---
        } elseif (isset($_POST['submit_document'])) {
            $marche_id = $_POST['marche_id'];
            $document_type = $_POST['document_type'];
            if (empty($document_type) || !isset($_FILES['fichier_document']) || $_FILES['fichier_document']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Type de document ou fichier manquant.");
            }

            handle_upload($_FILES['fichier_document'], $marche_id, $document_type, $pdo);
            
            $pdo->commit();
            $_SESSION['success'] = "Le document '$document_type' a été ajouté avec succès.";

        // --- Scénario 4 : Envoi Manuel à la Comptabilité ---
        } elseif (isset($_POST['submit_to_comptable'])) {
            $marche_id = $_POST['marche_id'];
            $besoin_id = $_POST['besoin_id'];

            // On vérifie qu'au moins un document est joint (en plus du PV)
            $doc_count = $pdo->query("SELECT COUNT(*) FROM documents_commande WHERE marche_id = '$marche_id'")->fetchColumn();
            if ($doc_count <= 1) { 
                 throw new Exception("Impossible d'envoyer le dossier à la comptabilité. Vous devez joindre au moins un document (Contrat, Bon de Commande, Facture...).");
            }

            $pdo->prepare("UPDATE marches SET statut = 'Facturé' WHERE id = ?")->execute([$marche_id]);
            $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?")->execute([$besoin_id]);

            $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE LOWER(role) = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($comptables as $comptable_id) {
                $message = "Un dossier d'Appel d'Offres ($marche_id) complet est prêt pour paiement.";
                $lien = "dossier_validation.php?besoin_id=$besoin_id";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
            }

            $pdo->commit();
            $_SESSION['success'] = "Le dossier finalisé a été transmis à la comptabilité pour traitement.";

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
                $_SESSION['success'] = "Le document a été remplacé avec succès.";
            } else {
                 throw new Exception("Erreur lors de la sauvegarde du nouveau fichier.");
            }
        
        // --- Scénario 6 : Resoumission après rejet ---
        } elseif (isset($_POST['resubmit_comptable'])) {
             $marche_id = $_POST['marche_id'];
             $besoin_id = $_POST['besoin_id'];
             
             $pdo->prepare("UPDATE marches SET statut = 'Facturé', motif_rejet = NULL WHERE id = ?")->execute([$marche_id]);
             $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?")->execute([$besoin_id]);

             $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE LOWER(role) = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
             foreach ($comptables as $comptable_id) {
                 $message = "Le dossier d'Appel d'Offres ($marche_id) a été corrigé par la logistique et resoumis.";
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
$stmt_ao = $pdo->prepare("
    SELECT ao.*, 
           b.titre AS besoin_titre, 
           b.id AS besoin_id, 
           b.montant AS besoin_montant, 
           b.description AS besoin_desc, 
           p.nom AS projet_nom,
           u.nom AS demandeur  
    FROM appels_offre ao 
    JOIN besoins b ON ao.besoin_id = b.id 
    LEFT JOIN projets p ON b.projet_id = p.id
    LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
    WHERE ao.id = ?
");
$stmt_ao->execute([$ao_id]);
$ao = $stmt_ao->fetch();

if (!$ao) { 
    header('Location: besoins_logisticien.php'); 
    exit(); 
}

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

// On récupère les articles pour le modal de détails
$stmt_art = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
$stmt_art->execute([$ao['besoin_id']]);
$articles = $stmt_art->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi Appel d'Offres - Logistique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div>
                <h3 class="h5 mb-0 fw-bold"><i class="bi bi-megaphone-fill text-dark me-2"></i>Suivi de l'Appel d'Offres</h3>
                <small class="text-muted">Réf AO: <code><?= htmlspecialchars($ao['id']) ?></code></small>
            </div>
            <a href="besoins_logisticien.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-2"></i>Retour</a>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

            <div class="alert alert-light border shadow-sm d-flex justify-content-between align-items-center mb-4">
                <div>
                    <span class="text-muted small text-uppercase d-block mb-1">Titre du marché à pourvoir :</span>
                    <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($ao['besoin_titre']) ?></h5>
                </div>
                <button type="button" class="btn btn-outline-dark fw-bold btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#modalBesoinDetails">
                    <i class="bi bi-eye me-1"></i> Voir les détails du besoin
                </button>
            </div>

            <?php if (!$marche): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0 border-top border-primary border-4">
                        <div class="card-header bg-white pt-4 pb-2 border-0 text-center">
                            <h5 class="fw-bold text-primary mb-1">Étape 1 : Dépouillement des Offres</h5>
                            <p class="text-muted small">Sélection du fournisseur gagnant suite à l'analyse des offres.</p>
                        </div>
                        <div class="card-body p-4 p-md-5">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                                <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                
                                <div class="row g-4 mb-4">
                                    <div class="col-md-8">
                                        <label for="fournisseur" class="form-label fw-bold small text-muted">NOM DU FOURNISSEUR RETENU <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg bg-light" id="fournisseur" name="fournisseur" placeholder="Entreprise gagnante..." required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="montant" class="form-label fw-bold small text-muted">MONTANT ATTRIBUÉ (CFA)</label>
                                        <input type="number" class="form-control form-control-lg fw-bold text-success bg-light" id="montant" name="montant" placeholder="Ex: 5000000">
                                    </div>
                                </div>
                                <div class="mb-5">
                                    <label for="pv" class="form-label fw-bold small text-danger"><i class="bi bi-file-earmark-text me-1"></i> PV DE DÉPOUILLEMENT <span class="text-danger">*</span></label>
                                    <input class="form-control border-danger" type="file" id="pv" name="pv" accept=".pdf,.jpg,.jpeg,.png" required>
                                </div>
                                
                                <div class="text-center">
                                    <button type="submit" name="submit_depouillement" class="btn btn-primary btn-lg fw-bold px-5 shadow">
                                        <i class="bi bi-check-circle me-2"></i>Valider l'attribution
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($marche && $marche['statut'] === 'Attribué'): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0 border-top border-success border-4 text-center">
                        <div class="card-header bg-white pt-4 border-0">
                            <h5 class="fw-bold text-success mb-0">Étape 2 : Choix de Contractualisation</h5>
                        </div>
                        <div class="card-body p-5">
                            <div class="mb-4">
                                <i class="bi bi-award text-warning" style="font-size: 4rem;"></i>
                                <h3 class="fw-bold text-dark mt-3">Marché attribué à <span class="text-primary"><?= htmlspecialchars($marche['fournisseur']) ?></span></h3>
                                <p class="text-muted">Par quel moyen officiel souhaitez-vous contractualiser cet achat ?</p>
                            </div>
                            
                            <div class="d-flex justify-content-center gap-3">
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <input type="hidden" name="type_contrat" value="Bon de Commande">
                                    <button type="submit" name="submit_choix_contrat" class="btn btn-outline-primary btn-lg px-4 py-3 fw-bold shadow-sm">
                                        <i class="bi bi-receipt fs-4 d-block mb-2"></i> Bon de Commande
                                    </button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <input type="hidden" name="type_contrat" value="Contrat">
                                    <button type="submit" name="submit_choix_contrat" class="btn btn-outline-dark btn-lg px-4 py-3 fw-bold shadow-sm">
                                        <i class="bi bi-file-earmark-text fs-4 d-block mb-2"></i> Contrat Formel
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($marche && in_array($marche['statut'], ['En cours', 'Facturé', 'Rejeté par Comptable', 'Paiement Approuvé'])): ?>
            
            <?php if ($marche['statut'] === 'Rejeté par Comptable' && !empty($marche['motif_rejet'])): ?>
                <div class="alert alert-danger shadow-sm border-danger">
                    <h5 class="alert-heading fw-bold"><i class="bi bi-x-circle-fill me-2"></i>Dossier Rejeté par la Comptabilité</h5>
                    <p class="mb-0"><strong>Motif :</strong> <?= htmlspecialchars($marche['motif_rejet']) ?></p>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white pt-4 pb-2 border-0">
                            <h5 class="fw-bold text-dark mb-0">Détails de l'Attribution</h5>
                        </div>
                        <div class="card-body p-4">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Fournisseur</span>
                                    <span class="badge bg-light text-dark border border-secondary fs-6"><i class="bi bi-shop me-2"></i><?= htmlspecialchars($marche['fournisseur']) ?></span>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Montant</span>
                                    <span class="text-success fw-bold fs-5"><?= $marche['montant'] ? number_format($marche['montant'], 0, ',', ' ') . ' CFA' : 'Non défini' ?></span>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Statut Actuel</span>
                                    <?php
                                        $s = $marche['statut'];
                                        $b_class = ($s == 'En cours') ? 'bg-primary' : (($s == 'Facturé' || $s == 'Paiement Approuvé') ? 'bg-success' : 'bg-danger');
                                    ?>
                                    <span class="badge <?= $b_class ?> fs-6"><?= htmlspecialchars($s) ?></span>
                                </li>
                                <li class="list-group-item px-0 py-3">
                                    <span class="text-muted small text-uppercase d-block">Moyen de contractualisation</span>
                                    <strong class="text-dark"><i class="bi bi-link-45deg me-2"></i><?= htmlspecialchars($marche['type_contrat']) ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                         <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-files me-2 text-primary"></i>Suivi des Documents</h5>
                            <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addDocumentModal" <?= $is_locked ? 'disabled' : '' ?>>
                                <i class="bi bi-plus-lg me-1"></i> Ajouter un fichier
                            </button>
                        </div>
                        
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-4">Type de document</th>
                                        <th>Fichier Joint</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-5">Aucun document ajouté.</td></tr>
                                <?php else: foreach ($documents as $doc): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark"><i class="bi bi-file-earmark-text text-secondary me-2"></i><?= htmlspecialchars($doc['type_document']) ?></td>
                                        <td>
                                            <a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" class="text-decoration-none small" target="_blank"><?= htmlspecialchars($doc['fichier_joint']) ?></a>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" download class="btn btn-sm btn-outline-primary shadow-sm" title="Télécharger"><i class="bi bi-download"></i></a>
                                            <?php if (!$is_locked): ?>
                                                <button class="btn btn-sm btn-warning fw-bold text-dark shadow-sm ms-1 edit-doc-btn" data-bs-toggle="modal" data-bs-target="#editDocumentModal" data-doc-id="<?= $doc['id'] ?>" data-doc-type="<?= htmlspecialchars($doc['type_document']) ?>" data-doc-file="<?= htmlspecialchars($doc['fichier_joint']) ?>" title="Remplacer"><i class="bi bi-pencil"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="card-footer bg-white border-top p-4 text-center">
                            <?php if ($marche['statut'] === 'En cours'): ?>
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <div class="alert alert-light border small text-muted mb-3"><i class="bi bi-info-circle me-1"></i> Envoyez le dossier à la comptabilité une fois que vous avez collecté tous les documents nécessaires (Contrat/BC, Facture...).</div>
                                    <button type="submit" name="submit_to_comptable" class="btn btn-success btn-lg fw-bold shadow px-5" onclick="return confirm('Attention : Êtes-vous sûr de vouloir soumettre ce dossier ? Il sera verrouillé.')">
                                        <i class="bi bi-send-check-fill me-2"></i> Transmettre le dossier au Comptable
                                    </button>
                                </form>
                            <?php elseif ($marche['statut'] === 'Rejeté par Comptable'): ?>
                                <form method="POST">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($ao['besoin_id']) ?>">
                                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                                    <button type="submit" name="resubmit_comptable" class="btn btn-warning btn-lg fw-bold shadow text-dark"><i class="bi bi-arrow-repeat me-2"></i> Soumettre à nouveau le dossier corrigé</button>
                                </form>
                            <?php elseif ($marche['statut'] === 'Facturé'): ?>
                                <span class="badge bg-light text-primary border border-primary fs-6 py-2 px-4"><i class="bi bi-hourglass-split me-2"></i> Dossier en attente de vérification par la Comptabilité</span>
                            <?php elseif ($marche['statut'] === 'Paiement Approuvé'): ?>
                                <span class="badge bg-success fs-6 py-2 px-4 shadow-sm"><i class="bi bi-check-all me-2"></i> Processus terminé. Paiement approuvé.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<div class="modal fade" id="modalBesoinDetails" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-info-circle me-2"></i>Details du Besoin</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row mb-4">
                    
                    <div class="col-sm-6">
    <span class="text-muted small text-uppercase fw-bold">Initiateur :</span><br>
    <i class="bi bi-person text-primary"></i> <?php echo htmlspecialchars($ao['demandeur'] ?? 'Non spécifié', ENT_QUOTES, 'UTF-8'); ?>
</div>
                    <div class="col-sm-6 text-end">
                        <span class="text-muted small text-uppercase fw-bold">Budget Validé :</span><br>
                        <span class="text-success fw-bold fs-5"><?= number_format($ao['besoin_montant'], 0, ',', ' ') ?> CFA</span>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <?php if (!empty($articles)): ?>
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Liste des articles demandés</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-sm mb-0">
                                    <thead class="table-light small text-muted">
                                        <tr><th>Désignation</th><th class="text-center">Quantité</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($articles as $art): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($art['designation']) ?></td>
                                                <td class="text-center fw-bold text-dark fs-6"><?= htmlspecialchars($art['quantite']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Termes de Référence / Description</h6>
                            <p class="text-dark mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($ao['besoin_desc'] ?? 'Aucune description.') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0"><h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-plus me-2"></i>Ajouter un document</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id ?? '') ?>">
                    <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                    <div class="mb-4">
                        <label for="document_type" class="form-label fw-bold small text-muted">TYPE DE DOCUMENT <span class="text-danger">*</span></label>
                        <select class="form-select border-primary" name="document_type" id="document_type" required>
                            <option value="">-- Sélectionner dans la liste --</option>
                            <?php 
                            $types_possibles = ['Contrat', 'Bon de Commande', 'Bon de Livraison', 'Facture', 'Dossier Client'];
                            foreach ($types_possibles as $type) {
                                if (!in_array($type, $documents_existants)) {
                                    echo "<option value=\"$type\">$type</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="fichier_document" class="form-label fw-bold small text-muted">SÉLECTIONNER LE FICHIER <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" name="fichier_document" accept=".pdf,.jpg,.png,.jpeg" required>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" name="submit_document" class="btn btn-primary fw-bold px-4">Enregistrer le fichier</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark border-0"><h5 class="modal-title fw-bold" id="editModalTitle"><i class="bi bi-pencil-square me-2"></i>Remplacer un document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="document_id" id="edit_document_id">
                <input type="hidden" name="fichier_actuel" id="edit_fichier_actuel">
                <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id ?? '') ?>">
                <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">
                
                <div class="modal-body p-4 bg-light">
                    <p class="small text-muted mb-1 text-uppercase">Fichier actuellement enregistré :</p>
                    <p><strong id="edit_current_file_name" class="text-danger"></strong></p>
                    <div class="mt-4"><label for="nouveau_fichier" class="form-label fw-bold small">NOUVEAU FICHIER <span class="text-danger">*</span></label><input class="form-control border-warning" type="file" name="nouveau_fichier" accept=".pdf,.jpg,.png,.jpeg" required></div>
                </div>
                <div class="modal-footer bg-white border-0"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" name="update_document" class="btn btn-warning fw-bold text-dark px-4">Mettre à jour le document</button></div>
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

            editModal.querySelector('#editModalTitle').innerHTML = `<i class="bi bi-pencil-square me-2"></i> Remplacer : ${docType}`;
            editModal.querySelector('#edit_document_id').value = docId;
            editModal.querySelector('#edit_fichier_actuel').value = docFile;
            editModal.querySelector('#edit_current_file_name').textContent = docFile;
        });
    }
</script>
</body>
</html>