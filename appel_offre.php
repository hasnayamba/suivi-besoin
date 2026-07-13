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

// --- TRAITEMENT DU FORMULAIRE DE LANCEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appel_offre'])) {
    $date_limite = trim($_POST['date_limite']);
    $canal = trim($_POST['canal_publication']);

    // Validation
    if (empty($date_limite) || empty($canal) || !isset($_FILES['dossier_ao']) || $_FILES['dossier_ao']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Veuillez remplir tous les champs et joindre le dossier d'appel d'offres.";
    } else {
        // Gestion du fichier uploadé
        $file = $_FILES['dossier_ao'];
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $newFileName = 'AO_' . $besoin_id . '_' . time() . '.' . $fileExtension;
        $destPath = __DIR__ . '/uploads/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $pdo->beginTransaction();
            try {
                // 1. Insérer dans la nouvelle table 'appels_offre'
                $ao_id = 'AO' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
                $sql_ao = "INSERT INTO appels_offre (id, besoin_id, dossier_ao, date_limite, canal_publication, statut) 
                           VALUES (?, ?, ?, ?, ?, 'Lancé')";
                $stmt_ao = $pdo->prepare($sql_ao);
                $stmt_ao->execute([$ao_id, $besoin_id, $newFileName, $date_limite, $canal]);
                
                // 2. Mettre à jour le statut du besoin initial
                $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Appel d\'Offre Lancé' WHERE id = ?");
                $stmt_besoin->execute([$besoin_id]);

                $pdo->commit();
                $_SESSION['success'] = "L'appel d'offres a été lancé avec succès.";
                header('Location: logisticien.php'); // Rediriger vers le tableau de bord
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Erreur lors de la sauvegarde du dossier.";
        }
    }
    header('Location: appel_offre.php?besoin_id=' . $besoin_id);
    exit();
}

// --- AFFICHAGE DU FORMULAIRE (GET) ---
$stmt = $pdo->prepare("SELECT titre FROM besoins WHERE id = ?");
$stmt->execute([$besoin_id]);
$besoin_titre = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lancer un Appel d'Offres</title>
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
                    <h2 class="mb-1">Lancer un Appel d'Offres</h2>
                    <p class="text-muted mb-0 small">Besoin: <?= htmlspecialchars($besoin_titre) ?></p>
                </div>
                <a href="view_besoin.php?id=<?= htmlspecialchars($besoin_id) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Détails de la publication</h5></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                        
                        <div class="mb-3">
                            <label for="dossier_ao" class="form-label">Dossier d'Appel d'Offres (DAO) <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="dossier_ao" name="dossier_ao" accept=".zip,.rar,.pdf,.doc,.docx,.xls,.xlsx" required>
                            <div class="form-text">Fichiers autorisés : PDF, ZIP, DOCX, XLSX, etc.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date_limite" class="form-label">Date limite de soumission <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_limite" name="date_limite" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="canal_publication" class="form-label">Canal de Publication <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="canal_publication" name="canal_publication" placeholder="Ex: Journal local, Site web, etc." required>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <button type="submit" name="submit_appel_offre" class="btn btn-primary btn-lg">
                                <i class="bi bi-megaphone me-2"></i> Lancer l'Appel d'Offres
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