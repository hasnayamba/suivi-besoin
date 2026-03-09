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
        // MODIFICATION ICI : On compte les dossiers "Facturé" ET "Facture Validée"
        $metrics['a_valider'] = $pdo->query("SELECT COUNT(*) FROM marches WHERE statut IN ('Facturé', 'Facture Validée')")->fetchColumn();
        
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

// MODIFICATION ICI : On récupère les dossiers "Facturé" ET "Facture Validée"
$marches_a_valider = $pdo->query("SELECT * FROM marches WHERE statut IN ('Facturé', 'Facture Validée') ORDER BY date_debut DESC")->fetchAll();
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
        <div class="p-4 border-bottom">
            <h5 class="mb-1 text-primary fw-bold">SWISSCONTACT</h5>
            <small class="text-muted fw-bold text-uppercase">Comptabilité</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link active fw-bold" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_approuves.php"><i class="bi bi-check2-circle text-success me-2"></i>Dossiers Approuvés</a></li>
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_rejetes.php"><i class="bi bi-x-circle text-danger me-2"></i>Dossiers Rejetés</a></li>
            </ul>
        </div>
    </nav>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold text-dark"><i class="bi bi-calculator me-2 text-primary"></i>Tableau de Bord</h2>
                    <p class="text-muted mb-0 small">Dossiers en attente de validation financière ou de paiement</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative border" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell text-dark"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="width: 320px;" aria-labelledby="notifDropdown">
                            <li class="dropdown-header fw-bold text-dark">Notifications</li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small text-center">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li class="px-3 py-2 <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?> border-bottom">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark">
                                        <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-primary mt-1" style="font-size: 0.75rem;"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?></div>
                                    </a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
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
                            <li><a class="dropdown-item text-danger fw-bold" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="row g-4 mb-5">
                <div class="col-md-6 col-lg-3">
                    <div class="card bg-primary text-white h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="card-title text-uppercase small fw-bold opacity-75">À valider / Payer</h6>
                            <h2 class="mb-0 fw-bold"><?= $metrics['a_valider'] ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card bg-success text-white h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="card-title text-uppercase small fw-bold opacity-75">Total Approuvés</h6>
                            <h2 class="mb-0 fw-bold"><?= $metrics['approuves_total'] ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card bg-danger text-white h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="card-title text-uppercase small fw-bold opacity-75">Total Rejetés</h6>
                            <h2 class="mb-0 fw-bold"><?= $metrics['rejetes_total'] ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="card bg-dark text-white h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="card-title text-uppercase small fw-bold opacity-75">Montant Approuvé (Année)</h6>
                            <h3 class="mb-0 fw-bold"><?= number_format($metrics['montant_approuve_annee'], 0, ',', ' ') ?> CFA</h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white pt-4 pb-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-dark"><i class="bi bi-list-check text-primary me-2"></i>Dossiers en attente d'action financière</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">ID Marché</th>
                                    <th>Titre du Besoin</th>
                                    <th>Fournisseur</th>
                                    <th>Montant Réclamé</th>
                                    <th>Statut Actuel</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($marches_a_valider)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-check-circle text-success fs-1 d-block mb-3"></i>Aucun dossier à valider ou à payer pour le moment. Beau travail !</td></tr>
                            <?php else: ?>
                                <?php foreach ($marches_a_valider as $marche): ?>
                                    <tr>
                                        <td class="ps-4"><code class="text-dark bg-light px-2 py-1 rounded border"><?= htmlspecialchars($marche['id']) ?></code></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($marche['titre']) ?></td>
                                        <td><i class="bi bi-shop text-muted me-2"></i><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                        <td class="fw-bold text-success fs-6"><?= number_format($marche['montant'], 0, ',', ' ') . ' CFA' ?></td>
                                        <td>
                                            <?php if($marche['statut'] === 'Facture Validée'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1"><i class="bi bi-cash-coin me-1"></i> Attente Paiement (Décaissement)</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark border border-warning px-2 py-1 shadow-sm"><i class="bi bi-search me-1"></i> Facture à Vérifier</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="dossier_validation.php?besoin_id=<?= htmlspecialchars($marche['besoin_id']) ?>" class="btn btn-sm btn-primary fw-bold shadow-sm">
                                                <i class="bi bi-folder2-open me-1"></i> Traiter le dossier
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
                if (notifBadge) {
                    notifBadge.textContent = data.unread_count;
                } else {
                    const button = document.getElementById('notifDropdown');
                    if(button) {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        newBadge.textContent = data.unread_count;
                        button.appendChild(newBadge);
                    }
                }
            } else {
                if (notifBadge) {
                    notifBadge.remove();
                }
            }
        })
        .catch(error => console.error('Erreur de vérification des notifications:', error));
}

// On lance la vérification toutes les 10 secondes
setInterval(checkForUpdates, 10000);
</script>

</body>
</html>