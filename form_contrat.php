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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contrat'])) {
    
    if (!isset($_FILES['contrat']) || $_FILES['contrat']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Veuillez joindre le fichier du contrat signé.";
        header('Location: form_contrat.php?marche_id=' . $marche_id);
        exit();
    }
    
    $file = $_FILES['contrat'];
    $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
    $newFileName = 'CONTRAT_' . $marche_id . '_' . time() . '.' . $fileExtension;
    $destPath = __DIR__ . '/uploads/' . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $pdo->beginTransaction();
        try {
            // 1. Enregistrer le document "Contrat"
            $doc_id = 'DOC_' . time() . rand(100, 999);
            $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$doc_id, $marche_id, 'Contrat', $newFileName]);

            // 2. Mettre à jour le statut du marché à "En cours"
            $stmt_marche = $pdo->prepare("UPDATE marches SET statut = 'En cours' WHERE id = ?");
            $stmt_marche->execute([$marche_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Le contrat a été enregistré. Le marché est maintenant 'En cours'.";
            
            // On redirige vers la page de gestion où il pourra ajouter BC, BL, Facture plus tard
            header('Location: gerer_marche.php?id=' . $marche_id); 
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur : " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Erreur lors de la sauvegarde du fichier contrat.";
    }
    
    header('Location: form_contrat.php?marche_id=' . $marche_id);
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
    <title>Flux Contrat Formel</title>
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
                    <h2 class="mb-1">Flux Contrat Formel</h2>
                    <p class="text-muted mb-0 small">Marché: <?= htmlspecialchars($marche['titre']) ?></p>
                </div>
            </div>
        </header>
        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Joindre le Contrat Signé</h5></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        
                        <div class="alert alert-info">Cette action enregistrera le contrat et marquera le marché comme "En cours". Vous pourrez ajouter les documents de livraison et de facturation plus tard depuis la page "Gérer les marchés".</div>
                        
                        <div class="mb-3">
                            <label for="contrat" class="form-label">Fichier du Contrat (PDF, DOCX...) <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="contrat" name="contrat" required>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="contractualisation.php?marche_id=<?= htmlspecialchars($marche_id) ?>" class="btn btn-secondary">Retour</a>
                            <button type="submit" name="submit_contrat" class="btn btn-primary">
                                <i class="bi bi-file-text me-1"></i> Enregistrer le Contrat
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