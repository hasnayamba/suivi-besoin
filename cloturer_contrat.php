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
    header('Location: contrat_dashboard.php');
    exit();
}

// 1. Récupération du contrat et de ses finances
$stmt = $pdo->prepare("SELECT * FROM contrats WHERE id = ?");
$stmt->execute([$id]);
$contrat = $stmt->fetch();

// On empêche d'accéder à la page si le contrat n'existe pas ou est déjà clôturé/rompu
if (!$contrat || $contrat['statut'] === 'Cloturé' || $contrat['statut'] === 'Rupture de contrat') {
    header('Location: contrat_dashboard.php');
    exit();
}

$montant_ht = (float)($contrat['montant_ht'] ?? 0);
$paiement_effectue = (float)($contrat['paiement_effectue'] ?? 0);
$solde_restant = $montant_ht - $paiement_effectue;

// 2. Traitement du formulaire de clôture
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nouveau_statut = $_POST['statut_final'] ?? 'Cloturé';
    $motif_rupture = trim($_POST['motif_rupture'] ?? '');

    try {
        // NOUVEAU : On utilise la nouvelle colonne motif_cloture
        if ($nouveau_statut === 'Rupture de contrat' && !empty($motif_rupture)) {
            $stmt_update = $pdo->prepare("UPDATE contrats SET statut = ?, motif_cloture = ? WHERE id = ?");
            $stmt_update->execute([$nouveau_statut, $motif_rupture, $id]);
        } else {
            // Clôture normale (tout est payé)
            $stmt_update = $pdo->prepare("UPDATE contrats SET statut = 'Cloturé', motif_cloture = NULL WHERE id = ?");
            $stmt_update->execute([$id]);
        }
        
        header('Location: contrat_dashboard.php');
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
    <title>Clôture du contrat - <?= htmlspecialchars($contrat['nom_fournisseur']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">

<div class="card shadow border-0" style="width: 100%; max-width: 600px;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-door-closed me-2"></i>Procédure de Clôture</h5>
    </div>
    <div class="card-body p-4">
        <h5 class="mb-3 text-primary"><?= htmlspecialchars($contrat['nom_fournisseur']) ?></h5>
        
        <div class="row mb-4">
            <div class="col-6">
                <small class="text-muted d-block">Montant Total :</small>
                <strong><?= number_format($montant_ht, 0, ',', ' ') ?> CFA</strong>
            </div>
            <div class="col-6">
                <small class="text-muted d-block">Solde Restant :</small>
                <strong class="<?= $solde_restant > 0 ? 'text-danger' : 'text-success' ?>">
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
                    <i class="bi bi-check-circle-fill me-2"></i> Le solde est à zéro. Le contrat peut être clôturé normalement.
                </div>
                <input type="hidden" name="statut_final" value="Cloturé">
                
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>Attention !</strong> Il reste <?= number_format($solde_restant, 0, ',', ' ') ?> CFA à payer. Vous ne pouvez pas faire une clôture standard.
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Action requise :</label>
                    <select name="statut_final" class="form-select" required>
                        <option value="">-- Choisissez une action --</option>
                        <option value="Rupture de contrat">Déclarer une Rupture de Contrat</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Justification obligatoire :</label>
                    <textarea name="motif_rupture" class="form-control border-danger" rows="3" placeholder="Expliquez pourquoi ce contrat s'arrête avant d'être totalement soldé..." required></textarea>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="contrat_dashboard.php" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-danger">Valider et Fermer le contrat</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>