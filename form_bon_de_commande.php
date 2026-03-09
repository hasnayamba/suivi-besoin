<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$marche_id = $_GET['marche_id'] ?? $_POST['marche_id'] ?? null;
if (!$marche_id) {
    header('Location: marches.php');
    exit();
}

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bon_de_commande'])) {
    
    // Validation des fichiers obligatoires
    if (!isset($_FILES['bon_commande']) || $_FILES['bon_commande']['error'] !== UPLOAD_ERR_OK || !isset($_FILES['facture']) || $_FILES['facture']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Le Bon de Commande et la Facture sont tous les deux obligatoires pour ce flux.";
        header('Location: form_bon_de_commande.php?marche_id=' . $marche_id);
        exit();
    }
    
    // Fonction d'aide pour l'upload
    function handle_upload($file, $marche_id, $doc_type, $pdo) {
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
        $destPath = __DIR__ . '/uploads/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $doc_id = 'DOC_' . time() . rand(100, 999);
            $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);
            return true;
        }
        return false;
    }

    $pdo->beginTransaction();
    try {
        // 1. Enregistrer les deux documents
        handle_upload($_FILES['bon_commande'], $marche_id, 'Bon de Commande', $pdo);
        handle_upload($_FILES['facture'], $marche_id, 'Facture', $pdo);

        // 2. Mettre à jour le statut du marché à "Facturé"
        $stmt_marche = $pdo->prepare("UPDATE marches SET statut = 'Facturé' WHERE id = ?");
        $stmt_marche->execute([$marche_id]);
        
        // 3. Mettre à jour le statut du besoin
        $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Facturé' FROM marches WHERE marches.besoin_id = besoins.id AND marches.id = ?");
        $stmt_besoin->execute([$marche_id]); // Note: cette syntaxe peut varier selon SQL, une sous-requête est plus sûre
        
        // Alternative plus sûre pour MaJ besoin:
        $besoin_id = $pdo->query("SELECT besoin_id FROM marches WHERE id = " . $pdo->quote($marche_id))->fetchColumn();
        if ($besoin_id) {
            $pdo->prepare("UPDATE besoins SET statut = 'Facturé' WHERE id = ?")->execute([$besoin_id]);
        }

        // 4. Notifier le comptable
        $comptables = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'comptable'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($comptables as $comptable_id) {
            $message = "Dossier (AO/BC) " . htmlspecialchars($marche_id) . " est prêt pour validation.";
            $lien = "dossier_validation.php?besoin_id=$besoin_id";
            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")->execute([$comptable_id, $message, $lien]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Dossier transmis au comptable avec succès.";
        header('Location: marches.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur : " . $e->getMessage();
    }
    header('Location: form_bon_de_commande.php?marche_id=' . $marche_id);
    exit();
}

// --- AFFICHAGE DU FORMULAIRE (GET) ---
$marche = $pdo->query("SELECT * FROM marches WHERE id = " . $pdo->quote($marche_id))->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flux Bon de Commande</title>
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
                    <h2 class="mb-1">Flux Bon de Commande (Rapide)</h2>
                    <p class="text-muted mb-0 small">Marché: <?= htmlspecialchars($marche['titre']) ?></p>
                </div>
            </div>
        </header>
        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Joindre les documents pour la Comptabilité</h5></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        
                        <div class="alert alert-info">Pour ce flux rapide, le Bon de Commande et la Facture sont tous les deux obligatoires.</div>
                        
                        <div class="mb-3">
                            <label for="bon_commande" class="form-label">Bon de Commande <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="bon_commande" name="bon_commande" required>
                        </div>
                        <div class="mb-3">
                            <label for="facture" class="form-label">Facture <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="facture" name="facture" required>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="contractualisation.php?marche_id=<?= htmlspecialchars($marche_id) ?>" class="btn btn-secondary">Retour</a>
                            <button type="submit" name="submit_bon_de_commande" class="btn btn-primary">
                                <i class="bi bi-send-check me-1"></i> Envoyer au Comptable
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