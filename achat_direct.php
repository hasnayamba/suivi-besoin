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
            $fileExtension = strtolower(pathinfo($fileName = basename($file['name']), PATHINFO_EXTENSION));
            $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
            $destPath = __DIR__ . '/uploads/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $doc_id = 'DOC_' . time() . rand(100, 999);
                $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);

                

                return true;
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
    

        // MODIFICATION : Ajout de la colonne 'type_procedure'
$sql_marche = "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id, type_procedure) 
               VALUES (?, ?, ?, ?, CURDATE(), 'Facturé', ?, 'Achat Direct')";
$stmt_marche = $pdo->prepare($sql_marche);
$stmt_marche->execute([$marche_id, $titre_besoin, $fournisseur, $montant, $besoin_id]);
        // 3. Mettre à jour le statut du besoin
        $stmt_update_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?");
        $stmt_update_besoin->execute([$besoin_id]);

        // 4. Gérer les fichiers (Facture est déjà vérifiée)
        handle_upload('facture', $marche_id, 'Facture', $pdo);
        handle_upload('proforma', $marche_id, 'Proforma', $pdo);
        handle_upload('bon_commande', $marche_id, 'Bon de Commande', $pdo);
        handle_upload('bon_livraison', $marche_id, 'Bon de Livraison', $pdo);

        // 5. Notifier le comptable
        $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($comptables as $comptable_id) {
            $message = "Nouveau dossier (Achat Direct) " . htmlspecialchars($marche_id) . " est prêt pour validation.";
            $lien = "dossier_validation.php?besoin_id=$besoin_id";
            $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
            $stmt_notif->execute([$comptable_id, $message, $lien]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "L'achat direct a été créé et le dossier a été transmis au comptable.";
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
    $stmt = $pdo->prepare("SELECT titre, description, montant FROM besoins WHERE id = ? AND statut = 'Validé'");
    $stmt->execute([$besoin_id]);
    $besoin = $stmt->fetch();
    if (!$besoin) {
        $_SESSION['error'] = "Ce besoin n'est pas éligible à un achat direct ou est introuvable.";
        header('Location: besoins_logisticien.php');
        exit();
    }
} catch (PDOException $e) {
    die("Erreur de chargement: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achat Direct</title>
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
                    <h2 class="mb-1">Procédure d'Achat Direct</h2>
                    <p class="text-muted mb-0 small">Besoin: <?= htmlspecialchars($besoin['titre']) ?></p>
                </div>
                <a href="view_besoin.php?id=<?= htmlspecialchars($besoin_id) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Renseigner les documents du marché</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="fournisseur" class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="fournisseur" name="fournisseur" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="montant" class="form-label">Montant Final (cfa) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="montant" name="montant" placeholder="Montant de la facture" required>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        <h5 class="mb-3">Joindre les documents</h5>
                        <p class="text-muted">La facture est obligatoire. Les autres documents sont facultatifs pour un achat direct.</p>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="facture" class="form-label fw-bold">Facture <span class="text-danger">*</span></label>
                                <input class="form-control" type="file" id="facture" name="facture" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="proforma" class="form-label">Proforma (Optionnel)</label>
                                <input class="form-control" type="file" id="proforma" name="proforma">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="bon_commande" class="form-label">Bon de Commande (Optionnel)</label>
                                <input class="form-control" type="file" id="bon_commande" name="bon_commande">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="bon_livraison" class="form-label">Bon de Livraison (Optionnel)</label>
                                <input class="form-control" type="file" id="bon_livraison" name="bon_livraison">
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <button type="submit" name="submit_achat_direct" class="btn btn-primary btn-lg">
                                <i class="bi bi-send-check me-2"></i>Créer le marché et envoyer au Comptable
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>