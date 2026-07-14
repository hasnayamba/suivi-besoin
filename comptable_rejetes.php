<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') { 
    header('Location: login.php'); 
    exit(); 
}

// --- DONNÉES UTILISATEUR ---
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- RÉCUPÉRATION DES MARCHÉS REJETÉS ---
$marches_rejetes = $pdo->query("SELECT * FROM marches WHERE statut = 'Rejeté par Comptable' ORDER BY date_debut DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dossiers Rejetés - Comptabilité</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom">
            <h5 class="mb-1 text-primary fw-bold">SWISSCONTACT</h5>
            <small class="text-muted fw-bold text-uppercase">Comptabilité</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                <li class="nav-item mb-1"><a class="nav-link active fw-bold text-danger" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Dossiers Rejetés</h2>
                    <p class="text-muted mb-0 small">Liste des dossiers renvoyés à la logistique pour correction</p>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2 text-primary"></i><span class="fw-bold"><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li class="px-3 py-2">
                            <div class="fw-bold text-dark"><?= htmlspecialchars($utilisateur_nom) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="deconnexion.php">Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="card shadow-sm border-0 border-top border-danger border-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">ID Marché</th>
                                    <th>Titre</th>
                                    <th>Fournisseur</th>
                                    <th>Montant</th>
                                    <th>Motif du rejet</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($marches_rejetes)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-check2-all fs-1 d-block mb-3 text-success"></i>Aucun dossier rejeté en attente. Tout est propre !</td></tr>
                                <?php else: ?>
                                    <?php foreach ($marches_rejetes as $marche): ?>
                                    <tr>
                                        <td class="ps-4"><code><?= htmlspecialchars($marche['id']) ?></code></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($marche['titre']) ?></td>
                                        <td><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                        <td><?= number_format($marche['montant'], 0, ',', ' ') ?> CFA</td>
                                        <td class="text-danger small fw-bold">
                                            <i class="bi bi-chat-quote me-1"></i>
                                            <?= htmlspecialchars(substr($marche['motif_rejet'], 0, 50)) . (strlen($marche['motif_rejet']) > 50 ? '...' : '') ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="dossier_validation.php?besoin_id=<?= $marche['besoin_id'] ?>" class="btn btn-sm btn-outline-danger">
                                                Revoir le dossier
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>