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
    // 1. On récupère les notifications : Non lues OU Lues il y a moins de 24h
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications 
                                 WHERE utilisateur_id = ? 
                                 AND (lue = 0 OR date_lecture > DATE_SUB(NOW(), INTERVAL 1 DAY)) 
                                 ORDER BY date_creation DESC LIMIT 15");
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();

    // 2. Compteur : Uniquement les vraies non lues
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de notification: " . $e->getMessage());
}

// Récupération des statistiques pour les badges
try {
    $stats_besoins = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Validé'")->fetchColumn();
    $stats_contrats = $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'Actif'")->fetchColumn();
    $stats_conventions = $pdo->query("SELECT COUNT(*) FROM conventions WHERE statut = 'En cours'")->fetchColumn();
} catch (PDOException $e) {
    $stats_besoins = 0;
    $stats_contrats = 0;
    $stats_conventions = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portail Logisticien | Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --swiss-blue: #0056b3;
            --swiss-blue-light: #007bff;
            --swiss-green: #28a745;
            --swiss-orange: #fd7e14;
            --swiss-purple: #6f42c1;
            --swiss-pink: #e83e8c;
            --swiss-gray-dark: #495057;
            --swiss-gray: #6c757d;
            --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff;
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-success: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-warning: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);
            --gradient-purple: linear-gradient(135deg, #6f42c1 0%, #d63384 100%);
        }
        
        * { box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        /* Header Styles */
        .main-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            z-index: 1040;
            position: relative;
        }
        
        /* Module Cards */
        .module-card {
            background: var(--swiss-white);
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
            position: relative;
            height: 100%;
        }
        
        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .module-card.achat::before { background: var(--gradient-primary); }
        .module-card.contrat::before { background: var(--gradient-success); }
        .module-card.convention::before { background: var(--gradient-purple); }
        
        .module-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .module-card:hover::before { transform: scaleX(1); }
        
        .module-card .card-body {
            padding: 2.5rem;
            position: relative;
            z-index: 2;
        }
        
        .module-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            transition: all 0.3s ease;
        }
        
        .module-card:hover .module-icon { transform: scale(1.1) rotate(5deg); }
        
        .achat .module-icon { background: var(--gradient-primary); }
        .contrat .module-icon { background: var(--gradient-success); }
        .convention .module-icon { background: var(--gradient-purple); }
        
        .module-card h3 {
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--swiss-gray-dark) 0%, var(--swiss-gray) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .module-card p {
            color: var(--swiss-gray);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        /* CORRECTION Z-INDEX POUR LES DROPDOWNS */
        .notification-dropdown {
            width: 380px;
            max-height: 400px;
            overflow-y: auto;
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            z-index: 1070 !important;
        }
        
        .dropdown-menu { z-index: 1060 !important; }
        .dropdown-menu.show { z-index: 1080 !important; }
        
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid var(--swiss-gray-light);
            transition: background-color 0.2s ease;
            white-space: normal; /* Important pour les longs messages */
        }
        
        .notification-item:hover { background-color: var(--swiss-gray-light); }
        
        .notification-item.unread {
            background-color: rgba(0, 123, 255, 0.05);
            border-left: 4px solid var(--swiss-blue);
        }
        .notification-item.unread .message-text { font-weight: 700; color: #000; }
        .notification-item .message-text { color: #555; }
        
        /* User Menu */
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in-up { animation: fadeInUp 0.6s ease-out; }
        
        /* Welcome Section */
        .welcome-section {
            text-align: center;
            padding: 3rem 0 1rem;
        }
        
        .welcome-title {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .welcome-subtitle {
            font-size: 1.1rem;
            color: var(--swiss-gray);
            margin-bottom: 2rem;
        }
        
        /* Stats Overview */
        .stats-overview {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        /* Button Styles */
        .btn-module {
            border: none;
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-module::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn-module:hover::before { left: 100%; }
        
        .btn-primary-module { background: var(--gradient-primary); color: white; }
        .btn-success-module { background: var(--gradient-success); color: white; }
        .btn-purple-module { background: var(--gradient-purple); color: white; }
        
        /* Loading Animation */
        .spinner { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="d-flex flex-column min-vh-100">
        <header class="main-header px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div>
                        <h2 class="h4 mb-1 fw-bold">SWISSCONTACT</h2>
                        <p class="text-muted mb-0 small">Centre de gestion</p>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative rounded-pill px-3" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?= $unread_count ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notifDropdown">
                            <li class="dropdown-header fw-bold px-3 py-2 border-bottom">Notifications Récentes</li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-4 text-center text-muted">
                                    <i class="bi bi-bell-slash display-6 mb-2"></i>
                                    <p class="mb-0">Aucune notification</p>
                                </li>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <li class="notification-item <?= $notif['lue'] ? '' : 'unread' ?>">
                                        <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark d-block">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-info-circle text-primary mt-1 me-2"></i>
                                                <div class="flex-grow-1">
                                                    <div class="small message-text"><?= htmlspecialchars($notif['message']) ?></div>
                                                    <div class="small text-muted fst-italic">
                                                        <?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <li class="border-top">
                                <a class="dropdown-item text-center text-primary small fw-bold py-2" href="#">Voir toutes les notifications</a>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center gap-2 rounded-pill px-3" type="button" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?= strtoupper(substr($utilisateur_nom, 0, 1)) ?>
                            </div>
                            <span class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></span>
                            <i class="bi bi-chevron-down small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-3">
                            <li class="px-3 py-2 border-bottom">
                                <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                            </li>
                            <li><hr class="dropdown-divider my-2"></li>
                            <li><a class="dropdown-item py-2" href="profile.php"><i class="bi bi-person me-2"></i>Mon profil</a></li>
                            <li><a class="dropdown-item py-2" href="settings.php"><i class="bi bi-gear me-2"></i>Paramètres</a></li>
                            <li><hr class="dropdown-divider my-2"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto px-3">
            <div class="container">
                <div class="welcome-section fade-in-up">
                    <h1 class="welcome-title">Bienvenue, <?= htmlspecialchars(explode(' ', $utilisateur_nom)[0]) ?> !</h1>
                    <p class="welcome-subtitle">Gérez efficacement vos processus logistiques et approvisionnements</p>
                </div>
  
                <div class="row g-4 justify-content-center mt-2">
                    
                    <div class="col-xl-4 col-lg-6 col-md-8">
                        <a href="logisticien.php" class="card text-decoration-none text-dark module-card achat h-100 fade-in-up" style="animation-delay: 0.1s;">
                            <div class="card-body text-center">
                                <div class="module-icon"><i class="bi bi-cart-check"></i></div>
                                <h3 class="card-title">Gestion Achat</h3>
                                <p class="text-muted">Gérez les demandes de besoins soumises par les services : Achats Directs, Proformas, Appels d'Offres.</p>
                                <div class="mt-4"><span class="btn btn-module btn-primary-module px-4">Accéder <i class="bi bi-arrow-right ms-2"></i></span></div>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-xl-4 col-lg-6 col-md-8">
                        <a href="contrat_dashboard.php" class="card text-decoration-none text-dark module-card contrat h-100 fade-in-up" style="animation-delay: 0.2s;">
                            <div class="card-body text-center">
                                <div class="module-icon"><i class="bi bi-file-text"></i></div>
                                <h3 class="card-title">Gestion Contrat</h3>
                                <p class="text-muted">Suivez les contrats-cadres, les mandats et les accords à long terme avec les fournisseurs.</p>
                                <div class="mt-4"><span class="btn btn-module btn-success-module px-4">Accéder <i class="bi bi-arrow-right ms-2"></i></span></div>
                            </div>
                        </a>
                    </div>

                    <div class="col-xl-4 col-lg-6 col-md-8">
                        <a href="convention_dashboard.php" class="card text-decoration-none text-dark module-card convention h-100 fade-in-up" style="animation-delay: 0.3s;">
                            <div class="card-body text-center">
                                <div class="module-icon"><i class="bi bi-people"></i></div>
                                <h3 class="card-title">Gestion Convention</h3>
                                <p class="text-muted">Gérez les conventions et les partenariats institutionnels et suivez les accords.</p>
                                <div class="mt-4"><span class="btn btn-module btn-purple-module px-4">Accéder <i class="bi bi-arrow-right ms-2"></i></span></div>
                            </div>
                        </a>
                    </div>

                </div>

                <div class="row mt-5 fade-in-up" style="animation-delay: 0.4s;">
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center py-4">
                                <h4 class="mb-3">Actions Rapides</h4>
                                <div class="d-flex flex-wrap justify-content-center gap-3">
                                    <a href="gestion_achats.php?filter=urgent" class="btn btn-outline-primary rounded-pill"><i class="bi bi-lightning me-2"></i>Besoins Urgents</a>
                                    <a href="contrat_dashboard.php?filter=expiring" class="btn btn-outline-warning rounded-pill"><i class="bi bi-clock me-2"></i>Contrats à Renouveler</a>
                                    <a href="convention_dashboard.php?filter=new" class="btn btn-outline-purple rounded-pill"><i class="bi bi-star me-2"></i>Nouvelles Conventions</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="bg-white border-top mt-5">
            <div class="container py-4">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <p class="text-muted mb-0 small">
                            <i class="bi bi-shield-check text-success me-1"></i>Session sécurisée • Connecté en tant que <?= htmlspecialchars($utilisateur_nom) ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <p class="text-muted mb-0 small">SWISSCONTACT • © <?= date('Y') ?> • v2.2.0</p>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- GESTION NOTIFICATIONS INTELLIGENTE ---
        
        // 1. Fonction pour marquer comme lu (au clic sur le dropdown)
        function markNotificationsRead() {
            const badge = document.getElementById('notifBadge');
            
            // Appel AJAX pour mettre à jour en BDD (date_lecture = NOW)
            fetch('marquer_notifications_lues.php', { method: 'POST' })
            .then(response => {
                if (response.ok) {
                    if (badge) badge.remove(); // Enlève le point rouge
                    
                    // Enlève le style "non lu" (fond bleu/gras) mais garde la notif
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                }
            })
            .catch(error => console.error('Erreur:', error));
        }

        // 2. Fonction de mise à jour automatique (Polling 10s)
        function checkForUpdates() {
            fetch('check_notifications.php')
            .then(response => response.json())
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
            .catch(error => console.error('Erreur Polling:', error));
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser les notifications
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) {
                notifDropdown.addEventListener('show.bs.dropdown', markNotificationsRead);
            }
            setInterval(checkForUpdates, 10000); // Polling toutes les 10s

            // Add smooth hover effects (Votre code existant)
            const moduleCards = document.querySelectorAll('.module-card');
            moduleCards.forEach(card => {
                card.addEventListener('mouseenter', function() { this.style.zIndex = '10'; });
                card.addEventListener('mouseleave', function() { this.style.zIndex = '1'; });
            });

            // Add click animation for all modules
            moduleCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    const btn = this.querySelector('.btn-module');
                    if (btn) {
                        const originalHtml = btn.innerHTML;
                        btn.innerHTML = '<i class="bi bi-arrow-repeat spinner me-2"></i>Chargement...';
                        btn.disabled = true;
                        setTimeout(() => {
                            btn.innerHTML = originalHtml;
                            btn.disabled = false;
                        }, 1500);
                    }
                });
            });

            // Correction Z-Index dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => { menu.style.zIndex = '1060'; });
            document.addEventListener('show.bs.dropdown', function(e) {
                const dropdownMenu = e.target.querySelector('.dropdown-menu');
                if (dropdownMenu) { dropdownMenu.style.zIndex = '1080'; }
            });
        });

        // Parallax effect
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset;
            const parallax = document.querySelector('.welcome-section');
            if (parallax) { parallax.style.transform = `translateY(${scrolled * 0.1}px)`; }
        });
    </script>
</body>
</html>