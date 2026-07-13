<?php
session_start();
include 'db_connect.php';

// --- GESTION DE L'UTILISATEUR ET DES ACCÈS ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- LOGIQUE POUR LES NOTIFICATIONS ---
$utilisateur_id = $_SESSION['user_id'];
$notifications = [];
$unread_count = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 5");
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de notification: " . $e->getMessage());
}

// --- RÉCUPÉRATION DES DONNÉES UTILISATEUR ---
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Logisticien';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- FONCTIONS POUR LES DONNÉES DU DASHBOARD ---
function get_dashboard_metrics($pdo) {
    $metrics = [
        'besoins_en_attente' => 0,
        'demandes_proforma_en_cours' => 0,
        'marches_en_cours_count' => 0,
        'montant_marches_en_cours' => 0,
        'montant_approuve_mois' => 0,
        'montant_approuve_annee' => 0,
    ];
    try {
        $metrics['besoins_en_attente'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'En attente de validation'")->fetchColumn();
        $metrics['demandes_proforma_en_cours'] = $pdo->query("SELECT COUNT(*) FROM demandes_proforma WHERE statut IN ('En attente', 'Réponses en cours')")->fetchColumn();
        $metrics['marches_en_cours_count'] = $pdo->query("SELECT COUNT(*) FROM marches WHERE statut = 'En cours'")->fetchColumn();
        
        $metrics['montant_marches_en_cours'] = $pdo->query("SELECT SUM(montant) FROM marches WHERE statut = 'En cours'")->fetchColumn() ?? 0;
        
        $stmt_mois = $pdo->prepare("SELECT SUM(montant) FROM marches WHERE statut = 'Paiement Approuvé' AND MONTH(date_debut) = MONTH(CURDATE()) AND YEAR(date_debut) = YEAR(CURDATE())");
        $stmt_mois->execute();
        $metrics['montant_approuve_mois'] = $stmt_mois->fetchColumn() ?? 0;

        $stmt_annee = $pdo->prepare("SELECT SUM(montant) FROM marches WHERE statut = 'Paiement Approuvé' AND YEAR(date_debut) = YEAR(CURDATE())");
        $stmt_annee->execute();
        $metrics['montant_approuve_annee'] = $stmt_annee->fetchColumn() ?? 0;

    } catch (PDOException $e) { /* Gérer l'erreur si besoin */ }
    return $metrics;
}

function get_actions_requises($pdo) {
    try {
        return $pdo->query("SELECT id, titre, date_soumission FROM besoins WHERE statut = 'En attente de validation' ORDER BY date_soumission DESC LIMIT 5")->fetchAll();
    } catch (PDOException $e) { return []; }
}

function get_marches_recents($pdo) {
    try {
        return $pdo->query("SELECT id, titre, fournisseur FROM marches ORDER BY date_debut DESC LIMIT 5")->fetchAll();
    } catch (PDOException $e) { return []; }
}

// --- Exécution des fonctions ---
$metrics = get_dashboard_metrics($pdo);
$actions_requises = get_actions_requises($pdo);
$marches_recents = get_marches_recents($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Logisticien</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php';  ?>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Tableau de Bord</h2>
                    <p class="text-muted mb-0 small">Vue d'ensemble et actions rapides</p>
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
                <div class="col-md-4"><div class="card text-center p-3 h-100"><h6 class="text-muted">Besoins à traiter</h6><h3 class="mb-0"><?= $metrics['besoins_en_attente'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100"><h6 class="text-muted">Proformas en cours</h6><h3 class="mb-0"><?= $metrics['demandes_proforma_en_cours'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100"><h6 class="text-muted">Marchés en cours</h6><h3 class="mb-0"><?= $metrics['marches_en_cours_count'] ?></h3></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card text-center p-3 h-100 bg-info text-white">
                        <h6 class="text-white-50">Montant Marchés en Cours</h6>
                        <h3 class="mb-0"><?= number_format($metrics['montant_marches_en_cours'], 0, ',', ' ') ?> cfa</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center p-3 h-100 bg-success text-white">
                        <h6 class="text-white-50">Approuvé (ce mois-ci)</h6>
                        <h3 class="mb-0"><?= number_format($metrics['montant_approuve_mois'], 0, ',', ' ') ?> cfa</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center p-3 h-100 bg-dark text-white">
                        <h6 class="text-white-50">Approuvé (cette année)</h6>
                        <h3 class="mb-0"><?= number_format($metrics['montant_approuve_annee'], 0, ',', ' ') ?> cfa</h3>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Actions Requises</h5></div>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($actions_requises)): ?>
                                <li class="list-group-item text-muted">Aucun besoin en attente de traitement.</li>
                            <?php else: foreach ($actions_requises as $action): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="view_besoin.php?id=<?= htmlspecialchars($action['id']) ?>"><?= htmlspecialchars($action['titre']) ?></a>
                                        <small class="d-block text-muted">Soumis le <?= date('d/m/Y', strtotime($action['date_soumission'])) ?></small>
                                    </div>
                                    <a href="view_besoin.php?id=<?= htmlspecialchars($action['id']) ?>" class="btn btn-sm btn-outline-primary">Traiter</a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                     <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-briefcase me-2 text-success"></i>Marchés Récents</h5></div>
                        <ul class="list-group list-group-flush">
                           <?php if (empty($marches_recents)): ?>
                                <li class="list-group-item text-muted">Aucun marché créé récemment.</li>
                            <?php else: foreach ($marches_recents as $marche): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="gerer_marche.php?id=<?= htmlspecialchars($marche['id']) ?>"><?= htmlspecialchars($marche['titre']) ?></a>
                                        <small class="d-block text-muted">Fournisseur: <?= htmlspecialchars($marche['fournisseur']) ?></small>
                                    </div>
                                    <a href="gerer_marche.php?id=<?= htmlspecialchars($marche['id']) ?>" class="btn btn-sm btn-outline-primary">Gérer</a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
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
                        document.querySelectorAll('.dropdown-menu .bg-light').forEach(item => {
                            item.classList.remove('bg-light', 'fw-bold');
                            item.classList.add('text-muted');
                        });
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        });
    }

    function checkForUpdates() {
        fetch('check_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifDropdown = document.getElementById('notifDropdown');
                if (!notifDropdown) return;
                let notifBadge = notifDropdown.querySelector('.badge');
                if (data.unread_count > 0) {
                    if (notifBadge) {
                        notifBadge.textContent = data.unread_count;
                    } else {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        newBadge.textContent = data.unread_count;
                        notifDropdown.appendChild(newBadge);
                    }
                } else {
                    if (notifBadge) {
                        notifBadge.remove();
                    }
                }
            })
            .catch(error => console.error('Erreur de vérification:', error));
    }
    setInterval(checkForUpdates, 10000); // Vérification toutes les 10 secondes
});
</script>

</body>
</html>