<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$besoin_id = $_GET['besoin_id'] ?? $_POST['besoin_id'] ?? null;
if (!$besoin_id) {
    header('Location: logisticien.php');
    exit();
}

// --- TRAITEMENT DU FORMULAIRE D'ACHAT DIRECT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_achat_direct'])) {
    $fournisseur = trim($_POST['fournisseur']);
    $montant = trim($_POST['montant']);

    // Validation des champs obligatoires
    if (empty($fournisseur) || empty($montant) || !isset($_FILES['facture']) || $_FILES['facture']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Le fournisseur, le montant et la facture sont obligatoires.";
        header('Location: achat_direct.php?besoin_id=' . $besoin_id);
        exit();
    }

    // Fonction d'aide pour gérer l'upload
    function handle_upload($file_key, $marche_id, $doc_type, $pdo) {
        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$file_key];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Sécurité des extensions
            if (in_array($fileExtension, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'])) {
                $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); 
                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $doc_id = 'DOC_' . time() . rand(100, 999);
                    $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);
                    return true;
                }
            }
        }
        return false;
    }

    $pdo->beginTransaction();
    try {
        // 1. Récupérer le titre du besoin
        $stmt_besoin = $pdo->prepare("SELECT titre FROM besoins WHERE id = ?");
        $stmt_besoin->execute([$besoin_id]);
        $titre_besoin = $stmt_besoin->fetchColumn();

        // 2. Créer le marché
        $marche_id = 'M' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
        
        $sql_marche = "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id, type_procedure) 
                       VALUES (?, ?, ?, ?, CURDATE(), 'Facturé', ?, 'Achat Direct')";
        $stmt_marche = $pdo->prepare($sql_marche);
        $stmt_marche->execute([$marche_id, $titre_besoin, $fournisseur, $montant, $besoin_id]);
        
        // 3. Mettre à jour le statut du besoin
        $stmt_update_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?");
        $stmt_update_besoin->execute([$besoin_id]);

        // 4. Gérer les fichiers (Facture est obligatoire)
        handle_upload('facture', $marche_id, 'Facture', $pdo);
        handle_upload('proforma', $marche_id, 'Proforma', $pdo);
        handle_upload('bon_commande', $marche_id, 'Bon de Commande', $pdo);
        handle_upload('bon_livraison', $marche_id, 'Bon de Livraison', $pdo);

        // 5. Notifier le comptable
        $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE LOWER(role) = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($comptables as $comptable_id) {
            $message = "Nouveau dossier d'Achat Direct ($marche_id) prêt pour validation/paiement.";
            $lien = "dossier_validation.php?besoin_id=$besoin_id"; 
            $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
            $stmt_notif->execute([$comptable_id, $message, $lien]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "L'achat direct a été créé avec succès et le dossier a été transmis à la comptabilité !";
        
        // Redirection vers le dashboard
        header('Location: logisticien.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur lors de la création de l'achat direct : " . $e->getMessage();
        header('Location: achat_direct.php?besoin_id=' . $besoin_id);
        exit();
    }
}

// --- AFFICHAGE DU FORMULAIRE (GET) ---
try {
    $stmt = $pdo->prepare("SELECT titre, description, montant FROM besoins WHERE id = ?");
    $stmt->execute([$besoin_id]);
    $besoin = $stmt->fetch();
    
    if (!$besoin) {
        $_SESSION['error'] = "Ce besoin est introuvable.";
        header('Location: besoins_logisticien.php');
        exit();
    }

    // On récupère les articles pour remplacer la description si c'est une Option A
    $stmt_art = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
    $stmt_art->execute([$besoin_id]);
    $articles = $stmt_art->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de chargement: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achat Direct - Logistique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div>
                <h3 class="h5 mb-0 fw-bold"><i class="bi bi-cart-check-fill text-success me-2"></i>Procédure d'Achat Direct</h3>
                <small class="text-muted">Génération du dossier pour la comptabilité</small>
            </div>
            <a href="view_besoin.php?id=<?= htmlspecialchars($besoin_id) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-2"></i>Retour au dossier</a>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white pb-0 border-0 pt-3">
                            <h6 class="fw-bold text-muted text-uppercase mb-0">Besoin Initial</h6>
                        </div>
                        <div class="card-body">
                            <h5 class="fw-bold text-dark mb-3"><?= htmlspecialchars($besoin['titre']) ?></h5>
                            <div class="mb-4">
                                <span class="d-block small text-muted">Montant Estimé (Finance) :</span>
                                <span class="fs-5 fw-bold text-primary"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</span>
                            </div>
                            
                            <div class="mb-0">
                                <?php if (!empty($articles)): ?>
                                    <span class="d-block small text-muted mb-2">Articles à acheter :</span>
                                    <div class="table-responsive border rounded" style="max-height: 250px; overflow-y: auto;">
                                        <table class="table table-sm table-striped mb-0 small">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th>Désignation</th>
                                                    <th class="text-center">Qté</th>
                                                </tr>
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
                                    <span class="d-block small text-muted mb-1">Description :</span>
                                    <div class="p-2 bg-light rounded small text-dark" style="max-height: 250px; overflow-y: auto; white-space: pre-wrap;">
                                        <?= htmlspecialchars($besoin['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="col-lg-8 mb-4">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white border-bottom pt-4 pb-3 px-4">
                            <h5 class="mb-0 fw-bold text-success"><i class="bi bi-file-earmark-text me-2"></i>Constitution du Dossier d'Achat</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                                
                                <h6 class="fw-bold border-bottom pb-2 mb-3">Informations de facturation</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <label for="fournisseur" class="form-label fw-bold small">Nom du Fournisseur retenu <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-light" id="fournisseur" name="fournisseur" placeholder="Ex: Entreprise XYZ..." required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="montant" class="form-label fw-bold small">Montant Final Facturé (CFA) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" class="form-control text-success fw-bold bg-light" id="montant" name="montant" value="<?= htmlspecialchars($besoin['montant']) ?>" required>
                                        <small class="text-muted">Ajustez si la facture diffère du devis.</small>
                                    </div>
                                </div>
                                
                                <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Pièces Justificatives</h6>
                                <div class="alert alert-light border small text-muted mb-4">
                                    <i class="bi bi-info-circle-fill me-1"></i> Pour un achat direct, seule la <strong>Facture</strong> est strictement obligatoire. Ajoutez les autres documents s'ils existent (Bon de commande, BL...).
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="facture" class="form-label fw-bold small text-danger"><i class="bi bi-asterisk me-1"></i> Facture</label>
                                        <input class="form-control border-danger" type="file" id="facture" name="facture" accept=".pdf,.jpg,.jpeg,.png" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="proforma" class="form-label fw-bold small text-muted">Proforma / Devis (Optionnel)</label>
                                        <input class="form-control bg-light" type="file" id="proforma" name="proforma" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="bon_commande" class="form-label fw-bold small text-muted">Bon de Commande (Optionnel)</label>
                                        <input class="form-control bg-light" type="file" id="bon_commande" name="bon_commande" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="bon_livraison" class="form-label fw-bold small text-muted">Bon de Livraison (Optionnel)</label>
                                        <input class="form-control bg-light" type="file" id="bon_livraison" name="bon_livraison" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                                
                                <div class="text-end mt-5 border-top pt-4">
                                    <button type="submit" name="submit_achat_direct" class="btn btn-success btn-lg fw-bold px-4 shadow" onclick="return confirm('Confirmez-vous la création de ce dossier et son envoi à la Comptabilité ?')">
                                        <i class="bi bi-send-check-fill me-2"></i>Valider et Transmettre en Compta
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>