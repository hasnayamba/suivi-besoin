<?php
session_start();
include 'db_connect.php';
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') { header('Location: login.php'); exit(); }
$marches_approuves = $pdo->query("SELECT * FROM marches WHERE statut = 'Paiement Approuvé' ORDER BY date_debut DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr"><head><title>Dossiers Approuvés</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom"><h5 class="mb-1">Comptabilité</h5></div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                <li class="nav-item mb-1"><a class="nav-link active" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                <li class="nav-item mb-1"><a class="nav-link" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
            </ul>
        </div>
    </nav>
    <div class="flex-fill d-flex flex-column">
        <header class="bg-white border-bottom px-4 py-3"> <h2 class="mb-1">Dossiers Approuvés</h2></header>
        <main class="flex-fill overflow-auto p-4">
            <div class="card">
                <div class="card-body">
                    <table class="table table-hover">
                        <thead><tr><th>ID Marché</th><th>Titre</th><th>Fournisseur</th><th>Montant</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($marches_approuves as $marche): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($marche['id']) ?></code></td>
                                <td><?= htmlspecialchars($marche['titre']) ?></td>
                                <td><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                <td><?= number_format($marche['montant'], 0, ',', ' ') ?> cfa</td>
                                <td class="text-end"><a href="dossier_validation.php?besoin_id=<?= $marche['besoin_id'] ?>" class="btn btn-sm btn-outline-secondary">Voir le dossier</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>