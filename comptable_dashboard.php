<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au comptable ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') {
    header('Location: login.php');
    exit();
}

// --- LOGIQUE POUR LES NOTIFICATIONS ---
$utilisateur_id = $_SESSION['user_id'];
$notifications = [];
$unread_count = 0;
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 5");
$stmt->execute([$utilisateur_id]);
$notifications = $stmt->fetchAll();
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
$stmt_count->execute([$utilisateur_id]);
$unread_count = $stmt_count->fetchColumn();


$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';


// --- FONCTION : Récupérer les statistiques pour le comptable ---
function get_comptable_metrics($pdo) {
    $metrics = [
        'a_valider' => 0,
        'approuves_total' => 0,
        'rejetes_total' => 0,
        'montant_approuve_annee' => 0,
    ];
    try {
        $metrics['a_valider'] = $pdo->query("SELECT COUNT(*) FROM marches WHERE statut = 'Facturé'")->fetchColumn();
        $metrics['approuves_total'] = $pdo->query("SELECT COUNT(*) FROM marches WHERE statut = 'Paiement Approuvé'")->fetchColumn();
        $metrics['rejetes_total'] = $pdo->query("SELECT COUNT(*) FROM marches WHERE statut = 'Rejeté par Comptable'")->fetchColumn();
        $stmt = $pdo->prepare("SELECT SUM(montant) FROM marches WHERE statut = 'Paiement Approuvé' AND YEAR(date_debut) = YEAR(CURDATE())");
        $stmt->execute();
        $metrics['montant_approuve_annee'] = $stmt->fetchColumn() ?? 0;
    } catch (PDOException $e) {
        // En cas d'erreur, les métriques restent à 0
    }
    return $metrics;
}

$metrics = get_comptable_metrics($pdo);
$marches_a_valider = $pdo->query("SELECT * FROM marches WHERE statut = 'Facturé' ORDER BY date_debut DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Comptabilité</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom"><h5 class="mb-1">SWISSCONTACT</h5>
        <small class="opacity-75">Comptabilité</small>
    </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link active" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                <li class="nav-item mb-1"><a class="nav-link" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                <li class="nav-item mb-1"><a class="nav-link" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
            </ul>
        </div>
    </nav>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Tableau de Bord Comptable</h2>
                    <p class="text-muted mb-0 small">Dossiers en attente de validation financière</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;" aria-labelledby="notifDropdown">
                            <li class="dropdown-header">Notifications</li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li class="px-3 py-2 <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark">
                                        <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-muted fst-italic"><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?></div>
                                    </a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-2"></i><span><?= htmlspecialchars($utilisateur_nom) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="px-3 py-2">
                                <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="deconnexion.php">Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3"><div class="card bg-primary text-white h-100"><div class="card-body"><h6 class="card-title">À valider</h6><h3><?= $metrics['a_valider'] ?></h3></div></div></div>
                <div class="col-md-6 col-lg-3"><div class="card bg-success text-white h-100"><div class="card-body"><h6 class="card-title">Total Approuvés</h6><h3><?= $metrics['approuves_total'] ?></h3></div></div></div>
                <div class="col-md-6 col-lg-3"><div class="card bg-danger text-white h-100"><div class="card-body"><h6 class="card-title">Total Rejetés</h6><h3><?= $metrics['rejetes_total'] ?></h3></div></div></div>
                <div class="col-md-6 col-lg-3"><div class="card bg-dark text-white h-100"><div class="card-body"><h6 class="card-title">Montant Approuvé (Année)</h6><h3><?= number_format($metrics['montant_approuve_annee'], 0, ',', ' ') ?> cfa</h3></div></div></div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Marchés à valider</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID Marché</th>
                                    <th>Titre</th>
                                    <th>Fournisseur</th>
                                    <th>Montant Final</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($marches_a_valider)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Aucun dossier à valider pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($marches_a_valider as $marche): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($marche['id']) ?></code></td>
                                        <td><?= htmlspecialchars($marche['titre']) ?></td>
                                        <td><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                        <td><?= number_format($marche['montant'], 0, ',', ' ') . ' cfa' ?></td>
                                        <td class="text-end">
                                            <a href="dossier_validation.php?besoin_id=<?= htmlspecialchars($marche['besoin_id']) ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-folder2-open me-1"></i> Ouvrir le dossier
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const notifDropdown = document.getElementById('notifDropdown');
    
    if (notifDropdown) {
        notifDropdown.addEventListener('show.bs.dropdown', function () {
            const unreadBadge = notifDropdown.querySelector('.badge');
            if (unreadBadge) {
                fetch('marquer_notifications_lues.php', { method: 'POST' })
                .then(response => {
                    if (response.ok) {
                        unreadBadge.remove();
                        const unreadItems = document.querySelectorAll('.dropdown-menu .bg-light');
                        unreadItems.forEach(item => {
                            item.classList.remove('bg-light', 'fw-bold');
                            item.classList.add('text-muted');
                        });
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        });
    }
});

    // NOUVEAU SCRIPT : Pour la mise à jour en temps réel
    function checkForUpdates() {
        fetch('check_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifBadge = document.querySelector('#notifDropdown .badge');
                
                if (data.unread_count > 0) {
                    // S'il y a des notifications non lues
                    if (notifBadge) {
                        // Si la pastille existe déjà, on met à jour le chiffre
                        notifBadge.textContent = data.unread_count;
                    } else {
                        // Sinon, on crée la pastille rouge
                        const button = document.getElementById('notifDropdown');
                        const newBadge = document.createElement('span');
                        newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        newBadge.textContent = data.unread_count;
                        button.appendChild(newBadge);
                    }
                } else {
                    // S'il n'y a plus de notification non lue, on supprime la pastille
                    if (notifBadge) {
                        notifBadge.remove();
                    }
                }
            })
            .catch(error => console.error('Erreur de vérification des notifications:', error));
    }

    // On lance la vérification toutes les 10 secondes (10000 millisecondes)
    setInterval(checkForUpdates, 10000);
</script>

</body>
</html>