<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$marche_id = $_GET['marche_id'] ?? null;
if (!$marche_id) {
    $_SESSION['error'] = "ID de marché manquant.";
    header('Location: suivi_ao.php');
    exit();
}

// Récupérer les infos du marché
$stmt = $pdo->prepare("SELECT * FROM marches WHERE id = ?");
$stmt->execute([$marche_id]);
$marche = $stmt->fetch();

if (!$marche || $marche['statut'] !== 'Attribué') {
     $_SESSION['error'] = "Ce marché n'est pas en attente de contractualisation.";
     header('Location: marches.php');
     exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contractualisation</title>
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
                    <h2 class="mb-1">Étape de Contractualisation</h2>
                    <p class="text-muted mb-0 small">Marché: <?= htmlspecialchars($marche['titre']) ?></p>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4 d-flex align-items-center justify-content-center">
            <div class="text-center">
                <div class="card shadow-sm">
                    <div class="card-body p-5">
                        <h3 class="card-title">Marché Attribué !</h3>
                        <p class="lead text-muted">Le marché a été attribué à **<?= htmlspecialchars($marche['fournisseur']) ?>**.</p>
                        <p>Veuillez maintenant choisir le type de document pour finaliser ce dossier :</p>
                        
                        <div class="d-grid gap-3 d-sm-flex justify-content-sm-center mt-4">
                            <a href="form_bon_de_commande.php?marche_id=<?= htmlspecialchars($marche_id) ?>" class="btn btn-primary btn-lg px-4 gap-3">
                                <i class="bi bi-receipt me-1"></i>
                                Option 1: Bon de Commande
                            </a>
                            <a href="form_contrat.php?marche_id=<?= htmlspecialchars($marche_id) ?>" class="btn btn-outline-secondary btn-lg px-4">
                                <i class="bi bi-file-text me-1"></i>
                                Option 2: Contrat Formel
                            </a>
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