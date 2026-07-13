<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au rôle 'finance' ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'finance') {
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

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Service Finance';
$utilisateur_email = $_SESSION['user_email'] ?? 'finance@example.com';

// --- RÉCUPÉRATION DES BESOINS À TRAITER ---
function get_besoins_a_valider($pdo) {
    try {
        // On récupère les besoins en attente de finance + le nom du demandeur
        $sql = "SELECT b.id, b.titre, b.date_soumission, u.nom AS nom_demandeur
                FROM besoins b
                LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id
                WHERE b.statut = 'En attente de Finance'
                ORDER BY b.date_soumission ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$besoins_a_valider = get_besoins_a_valider($pdo);

// --- STATISTIQUES POUR LE DASHBOARD ---
try {
    $stats = [
        'en_attente' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'En attente de Finance'")->fetchColumn(),
        'valides' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Validé'")->fetchColumn(),
        'rejetes' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut LIKE 'Rejeté%'")->fetchColumn(),
        'total' => $pdo->query("SELECT COUNT(*) FROM besoins")->fetchColumn()
    ];
} catch (PDOException $e) {
    $stats = ['en_attente' => 0, 'valides' => 0, 'rejetes' => 0, 'total' => 0];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Finance | Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --swiss-blue: #0056b3;
            --swiss-blue-light: #007bff;
            --swiss-green: #28a745;
            --swiss-red: #dc3545;
            --swiss-orange: #fd7e14;
            --swiss-gray-dark: #495057;
            --swiss-gray: #6c757d;
            --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff;
            --sidebar-width: 260px;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--swiss-white);
            border-right: 1px solid var(--swiss-gray-light);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        
        .sidebar-brand {
            padding: 1.5rem;
            border-bottom: 1px solid var(--swiss-gray-light);
            background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%);
            color: white;
        }
        
        .sidebar .nav-link {
            color: var(--swiss-gray-dark);
            padding: 0.75rem 1rem;
            margin: 0.2rem 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active {
            background-color: var(--swiss-blue);
            color: white;
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 0.5rem;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        /* Header */
        .main-header {
            background: var(--swiss-white);
            border-bottom: 1px solid var(--swiss-gray-light);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        /* Cards */
        .dashboard-card {
            background: var(--swiss-white);
            border: 1px solid var(--swiss-gray-light);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        
        .card-header {
            background: var(--swiss-white);
            border-bottom: 1px solid var(--swiss-gray-light);
            padding: 1.25rem;
        }
        
        /* Stats Cards */
        .stat-card {
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.pending {
            background: linear-gradient(135deg, var(--swiss-orange) 0%, #ffc107 100%);
        }
        
        .stat-card.approved {
            background: linear-gradient(135deg, var(--swiss-green) 0%, #20c997 100%);
        }
        
        .stat-card.rejected {
            background: linear-gradient(135deg, var(--swiss-red) 0%, #e83e8c 100%);
        }
        
        .stat-card.total {
            background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* Buttons */
        .btn-swiss {
            background: var(--swiss-blue);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-swiss:hover {
            background: var(--swiss-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3);
            color: white;
        }
        
        /* Table */
        .table-responsive {
            border-radius: 8px;
        }
        
        .table th {
            background-color: var(--swiss-gray-light);
            border-bottom: 2px solid var(--swiss-gray);
            font-weight: 600;
            color: var(--swiss-gray-dark);
            padding: 1rem 0.75rem;
        }
        
        .table td {
            vertical-align: middle;
            padding: 1rem 0.75rem;
        }
        
        /* Badges */
        .badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
        }
        
        /* Notifications */
        .notification-dropdown {
            width: 350px;
        }
        
        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--swiss-gray-light);
            transition: background-color 0.2s ease;
        }
        
        .notification-item:hover {
            background-color: var(--swiss-gray-light);
        }
        
        .notification-item.unread {
            background-color: rgba(0, 123, 255, 0.05);
            font-weight: 500;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block;
            }
        }
        
        @media (max-width: 768px) {
            .main-header {
                padding: 1rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-number {
                font-size: 2rem;
            }
            
            .notification-dropdown {
                width: 300px;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
        }
        
        @media (max-width: 576px) {
            .main-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -50px !important;
            }
        }
        
        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--swiss-gray-dark);
        }
        
        @media (max-width: 992px) {
            .mobile-menu-btn {
                display: block;
            }
        }
        
        /* Grid Layout */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Loading Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--swiss-gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5 class="mb-1">SWISSCONTACT</h5>
            <small class="opacity-75">Service Finance</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1">
                    <a class="nav-link active" href="finance_dashboard.php">
                        <i class="bi bi-wallet2 me-2"></i>Validation Budget
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link" href="finance_historique.php">
                        <i class="bi bi-clock-history me-2"></i>Historique
                    </a>
                </li>
                
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <header class="main-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button class="mobile-menu-btn me-3" id="mobileMenuBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div>
                    <h2 class="h4 mb-1">Tableau de Bord Finance</h2>
                    <p class="text-muted mb-0 small">Validation budgétaire et suivi des besoins</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3 header-actions">
                <!-- Notifications -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                        <i class="bi bi-bell"></i>
                        <?php if ($unread_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $unread_count ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notifDropdown">
                        <li class="dropdown-header fw-bold">Notifications</li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if (empty($notifications)): ?>
                            <li class="px-3 py-2 text-muted small text-center">Aucune notification</li>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <li class="notification-item <?= $notif['lue'] ? '' : 'unread' ?>">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark d-block">
                                        <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-muted fst-italic">
                                            <?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- User Menu -->
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2"></i>
                        <span><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="px-3 py-2">
                            <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="deconnexion.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Déconnexion
                        </a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-4">
            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card pending">
                    <div class="stat-number"><?= $stats['en_attente'] ?></div>
                    <div class="stat-label">
                        <i class="bi bi-clock-history me-1"></i>En Attente
                    </div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-number"><?= $stats['valides'] ?></div>
                    <div class="stat-label">
                        <i class="bi bi-check-circle me-1"></i>Validés
                    </div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-number"><?= $stats['rejetes'] ?></div>
                    <div class="stat-label">
                        <i class="bi bi-x-circle me-1"></i>Rejetés
                    </div>
                </div>
                <div class="stat-card total">
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label">
                        <i class="bi bi-folder me-1"></i>Total Besoins
                    </div>
                </div>
            </div>

            <!-- Besoins Table -->
            <div class="dashboard-card fade-in">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check me-2"></i>Besoins en attente de validation
                    </h5>
                    <span class="badge bg-warning text-dark">
                        <?= count($besoins_a_valider) ?> en attente
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($besoins_a_valider)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <h5 class="text-muted">Aucun besoin en attente</h5>
                            <p class="text-muted">Tous les besoins ont été traités.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID Besoin</th>
                                        <th>Titre</th>
                                        <th>Demandeur</th>
                                        <th>Date Soumission</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($besoins_a_valider as $besoin): ?>
                                        <tr>
                                            <td>
                                                <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($besoin['id']) ?></code>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($besoin['titre']) ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <i class="bi bi-person me-1"></i>
                                                    <?= htmlspecialchars($besoin['nom_demandeur'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?>
                                                </small>
                                            </td>
                                            <td class="text-end">
                                                <a href="finance_view_besoin.php?id=<?= htmlspecialchars($besoin['id']) ?>" 
                                                   class="btn btn-swiss btn-sm">
                                                    <i class="bi bi-folder2-open me-1"></i> Examiner
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-lightning me-2"></i>Actions Rapides
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                
                                <a href="finance_historique.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-search me-2"></i>Consulter l'historique
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>Informations
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info mb-0">
                                <small>
                                    <i class="bi bi-lightbulb me-1"></i>
                                    <strong>Conseil :</strong> Validez ou rejetez les besoins dans un délai de 48h pour une gestion optimale.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Close mobile menu when clicking on a link
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    document.getElementById('sidebar').classList.remove('mobile-open');
                }
            });
        });

        // Notification handling
        document.addEventListener('DOMContentLoaded', function() {
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) {
                notifDropdown.addEventListener('show.bs.dropdown', function () {
                    const unreadBadge = notifDropdown.querySelector('.badge');
                    if (unreadBadge) {
                        fetch('marquer_notifications_lues.php', { 
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            }
                        })
                        .then(response => {
                            if (response.ok) {
                                unreadBadge.remove();
                                // Marquer visuellement les notifications comme lues
                                document.querySelectorAll('.notification-item.unread').forEach(item => {
                                    item.classList.remove('unread');
                                });
                            }
                        })
                        .catch(error => console.error('Erreur:', error));
                    }
                });
            }

            // Polling pour les nouvelles notifications
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
                    .catch(error => console.error('Erreur:', error));
            }

            // Vérifier les nouvelles notifications toutes les 30 secondes
            setInterval(checkForUpdates, 30000);
        });
    </script>
</body>
</html>