<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Logisticien';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- LOGIQUE INTELLIGENTE POUR LES NOTIFICATIONS ---
$notifications = [];
$unread_count = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications 
                                 WHERE utilisateur_id = ? 
                                 AND (lue = 0 OR date_lecture > DATE_SUB(NOW(), INTERVAL 1 DAY)) 
                                 ORDER BY date_creation DESC LIMIT 15");
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de notification: " . $e->getMessage());
}

// --- FONCTIONS DASHBOARD ---
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

    } catch (PDOException $e) { }
    return $metrics;
}

// --- PAGINATION ACTIONS REQUISES ---
$items_per_page = 5; // Nombre d'actions par "page" dans le widget
$page_action = isset($_GET['page_action']) ? max(1, (int)$_GET['page_action']) : 1;
$offset_action = ($page_action - 1) * $items_per_page;

// Fonction modifiée pour supporter la pagination
function get_actions_requises($pdo, $limit, $offset) {
    try {
        // On récupère uniquement une tranche de résultats (LIMIT ... OFFSET ...)
        $sql = "SELECT id, titre, date_soumission 
                FROM besoins 
                WHERE statut = 'En attente de validation' 
                ORDER BY date_soumission DESC 
                LIMIT $limit OFFSET $offset";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) { return []; }
}

// Compter le total pour savoir combien de pages il y a
function count_actions_requises($pdo) {
    try {
        return $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'En attente de validation'")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

function get_marches_recents($pdo) {
    try {
        return $pdo->query("SELECT id, titre, fournisseur FROM marches ORDER BY date_debut DESC LIMIT 5")->fetchAll();
    } catch (PDOException $e) { return []; }
}

// --- EXÉCUTION ---
$metrics = get_dashboard_metrics($pdo);
$actions_requises = get_actions_requises($pdo, $items_per_page, $offset_action);
$total_actions = count_actions_requises($pdo);
$total_pages_actions = ceil($total_actions / $items_per_page);
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
    <style>
        .notification-dropdown { width: 320px; max-height: 400px; overflow-y: auto; }
        .notification-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0; transition: background-color 0.2s ease; white-space: normal; }
        .notification-item:hover { background-color: #f8f9fa; }
        .notification-item.unread { background-color: rgba(13, 110, 253, 0.08); border-left: 3px solid #0d6efd; }
        .notification-item.unread .message-text { font-weight: 700; color: #000; }
        .notification-item .message-text { color: #555; }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; ?>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold text-primary">Tableau de Bord</h2>
                    <p class="text-muted mb-0 small">Vue d'ensemble et actions rapides</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative border" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown shadow" aria-labelledby="notifDropdown">
                            <li class="dropdown-header fw-bold text-dark">Notifications récentes</li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small text-center">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li>
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="dropdown-item notification-item <?= $notif['lue'] ? '' : 'unread' ?>">
                                        <div class="message-text small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-primary mt-1" style="font-size: 0.75rem;">
                                            <i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-2 text-primary"></i><span class="fw-bold"><?= htmlspecialchars($utilisateur_nom) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li class="px-3 py-2">
                                <div class="fw-bold"><?= htmlspecialchars($utilisateur_nom) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="row g-4 mb-4">
                <div class="col-md-4"><div class="card text-center p-3 h-100 shadow-sm border-0"><h6 class="text-muted text-uppercase small fw-bold">Besoins à traiter</h6><h3 class="mb-0 text-primary fw-bold"><?= $metrics['besoins_en_attente'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100 shadow-sm border-0"><h6 class="text-muted text-uppercase small fw-bold">Proformas en cours</h6><h3 class="mb-0 text-warning fw-bold"><?= $metrics['demandes_proforma_en_cours'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100 shadow-sm border-0"><h6 class="text-muted text-uppercase small fw-bold">Marchés en cours</h6><h3 class="mb-0 text-success fw-bold"><?= $metrics['marches_en_cours_count'] ?></h3></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4"><div class="card text-center p-3 h-100 bg-primary text-white border-0 shadow-sm"><h6 class="text-white-50 text-uppercase small fw-bold">Montant Marchés (En cours)</h6><h3 class="mb-0"><?= number_format($metrics['montant_marches_en_cours'], 0, ',', ' ') ?> CFA</h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100 bg-success text-white border-0 shadow-sm"><h6 class="text-white-50 text-uppercase small fw-bold">Approuvé (Ce mois)</h6><h3 class="mb-0"><?= number_format($metrics['montant_approuve_mois'], 0, ',', ' ') ?> CFA</h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100 bg-dark text-white border-0 shadow-sm"><h6 class="text-white-50 text-uppercase small fw-bold">Approuvé (Cette année)</h6><h3 class="mb-0"><?= number_format($metrics['montant_approuve_annee'], 0, ',', ' ') ?> CFA</h3></div></div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Actions Requises</h5>
                            <?php if ($total_actions > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $total_actions ?></span>
                            <?php endif; ?>
                        </div>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($actions_requises)): ?>
                                <li class="list-group-item text-muted text-center py-4">Aucun besoin en attente de traitement.</li>
                            <?php else: foreach ($actions_requises as $action): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($action['titre']) ?></div>
                                        <small class="d-block text-muted">Soumis le <?= date('d/m/Y', strtotime($action['date_soumission'])) ?></small>
                                    </div>
                                    <a href="view_besoin.php?id=<?= htmlspecialchars($action['id']) ?>" class="btn btn-sm btn-primary fw-bold">Traiter</a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                        
                        <?php if ($total_pages_actions > 1): ?>
                        <div class="card-footer bg-white border-0 py-3">
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm justify-content-center mb-0">
                                    <li class="page-item <?= ($page_action <= 1) ? 'disabled' : '' ?>">
                                        <a class="page-link border-0 text-dark" href="?page_action=<?= $page_action - 1 ?>" aria-label="Précédent">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                    
                                    <li class="page-item disabled">
                                        <span class="page-link border-0 text-muted bg-transparent">
                                            Page <?= $page_action ?> / <?= $total_pages_actions ?>
                                        </span>
                                    </li>

                                    <li class="page-item <?= ($page_action >= $total_pages_actions) ? 'disabled' : '' ?>">
                                        <a class="page-link border-0 text-dark" href="?page_action=<?= $page_action + 1 ?>" aria-label="Suivant">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-lg-6">
                     <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white border-0 pt-3">
                            <h5 class="card-title mb-0 fw-bold text-success"><i class="bi bi-briefcase-fill me-2"></i>Marchés Récents</h5>
                        </div>
                        <ul class="list-group list-group-flush">
                           <?php if (empty($marches_recents)): ?>
                                <li class="list-group-item text-muted text-center py-4">Aucun marché récent.</li>
                            <?php else: foreach ($marches_recents as $marche): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($marche['titre']) ?></div>
                                        <small class="d-block text-muted"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($marche['fournisseur']) ?></small>
                                    </div>
                                    <a href="gerer_marche.php?id=<?= htmlspecialchars($marche['id']) ?>" class="btn btn-sm btn-outline-secondary">Gérer</a>
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
    // --- GESTION NOTIFICATIONS INTELLIGENTE ---
    function markNotificationsRead() {
        const badge = document.getElementById('notifBadge');
        if (badge) {
            fetch('marquer_notifications_lues.php', { method: 'POST' })
            .then(res => {
                if(res.ok) {
                    badge.remove();
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                }
            })
            .catch(err => console.error(err));
        }
    }

    function checkForUpdates() {
        fetch('check_notifications.php')
            .then(res => res.json())
            .then(data => {
                const notifBtn = document.getElementById('notifDropdown');
                const existingBadge = document.getElementById('notifBadge');

                if (data.unread_count > 0) {
                    if (existingBadge) {
                        existingBadge.textContent = data.unread_count;
                    } else if (notifBtn) {
                        const newBadge = document.createElement('span');
                        newBadge.id = 'notifBadge';
                        newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        newBadge.textContent = data.unread_count;
                        notifBtn.appendChild(newBadge);
                    }
                } else {
                    if (existingBadge) existingBadge.remove();
                }
            })
            .catch(err => console.error(err));
    }

    document.addEventListener('DOMContentLoaded', function() {
        const notifDropdown = document.getElementById('notifDropdown');
        if (notifDropdown) {
            notifDropdown.addEventListener('show.bs.dropdown', markNotificationsRead);
        }
        setInterval(checkForUpdates, 10000);
    });
</script>
</body>
</html>