<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: conventions_dashboard.php');
    exit();
}

// 1. Récupération de la convention et de ses finances
$stmt = $pdo->prepare("SELECT * FROM conventions WHERE id = ?");
$stmt->execute([$id]);
$convention = $stmt->fetch();

// On empêche l'accès si la convention n'existe pas ou est déjà terminée
if (!$convention || $convention['statut'] === 'Terminé') {
    header('Location: conventions_dashboard.php');
    exit();
}

// Calcul du solde
$montant_global = (float)($convention['montant_global'] ?? 0);
$paiements_effectues = (float)($convention['paiements_effectues'] ?? 0);
$solde_restant = $montant_global - $paiements_effectues;

// 2. Traitement du formulaire de clôture
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motif_rupture = trim($_POST['motif_rupture'] ?? '');

    try {
        if ($solde_restant > 0 && !empty($motif_rupture)) {
            // S'il y a un solde et un motif, on l'ajoute aux observations
            $nouvelle_observation = $convention['observations'] . "\n\n[TERMINÉ LE " . date('d/m/Y') . " - SOLDE NON ÉPUISÉ] Motif : " . $motif_rupture;
            $stmt_update = $pdo->prepare("UPDATE conventions SET statut = 'Terminé', observations = ? WHERE id = ?");
            $stmt_update->execute([$nouvelle_observation, $id]);
        } else {
            // Clôture normale (Tout est payé ou pas de motif requis)
            $stmt_update = $pdo->prepare("UPDATE conventions SET statut = 'Terminé' WHERE id = ?");
            $stmt_update->execute([$id]);
        }
        
        // Redirection avec un message de succès
        $_SESSION['success'] = "La convention a été clôturée avec succès.";
        header('Location: conventions_dashboard.php');
        exit();
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la clôture : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Terminer la convention - <?= htmlspecialchars($convention['nom_partenaire']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">

<div class="card shadow border-0" style="width: 100%; max-width: 600px;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-door-closed me-2"></i>Clôture de la Convention</h5>
    </div>
    <div class="card-body p-4">
        <h5 class="mb-3 text-primary"><?= htmlspecialchars($convention['nom_partenaire']) ?></h5>
        <p class="text-muted small mb-4">Réf : <?= htmlspecialchars($convention['num_convention']) ?></p>
        
        <div class="row mb-4 p-3 bg-light rounded border">
            <div class="col-6">
                <small class="text-muted d-block mb-1">Montant Global :</small>
                <strong class="fs-5"><?= number_format($montant_global, 0, ',', ' ') ?> CFA</strong>
            </div>
            <div class="col-6 border-start">
                <small class="text-muted d-block mb-1">Solde Restant :</small>
                <strong class="fs-5 <?= $solde_restant > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($solde_restant, 0, ',', ' ') ?> CFA
                </strong>
            </div>
        </div>

        <?php if (isset($erreur)): ?>
            <div class="alert alert-danger"><?= $erreur ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php if ($solde_restant <= 0): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i> Le solde est à zéro. La convention peut être terminée normalement.
                </div>
                
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>Attention !</strong> Il reste <?= number_format($solde_restant, 0, ',', ' ') ?> CFA sur cette convention.
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Justification obligatoire :</label>
                    <textarea name="motif_rupture" class="form-control border-danger" rows="3" placeholder="Expliquez pourquoi cette convention est terminée avant l'épuisement des fonds (ex: Fin des activités, rupture...)" required></textarea>
                    <small class="text-muted">Cette note sera ajoutée à l'historique de la convention.</small>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="convention_dashboard.php" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i> Valider et Terminer</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>