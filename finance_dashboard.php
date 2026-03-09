<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au rôle 'finance' ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'finance') {
    header('Location: login.php');
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Service Finance';
$utilisateur_email = $_SESSION['user_email'] ?? 'finance@swisscontact.org';

// --- 1. LOGIQUE DE NOTIFICATIONS INTELLIGENTE ---
$notifications = [];
$unread_count = 0;
try {
    // On récupère : Les non-lues (lue=0) OU celles lues il y a moins de 24h
    $sql_notif = "SELECT * FROM notifications 
                  WHERE utilisateur_id = ? 
                  AND (lue = 0 OR date_lecture > DATE_SUB(NOW(), INTERVAL 1 DAY)) 
                  ORDER BY date_creation DESC LIMIT 15";
    $stmt_notif = $pdo->prepare($sql_notif);
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();

    // Compteur : Uniquement les vraies non-lues pour le badge rouge
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur notif: " . $e->getMessage());
}

// --- 2. RÉCUPÉRATION DES BESOINS À VALIDER ---
$besoins_a_valider = [];
try {
    $sql_besoins = "SELECT b.id, b.titre, b.montant, b.date_soumission, u.nom AS nom_demandeur, p.nom as nom_projet
                    FROM besoins b
                    LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id
                    LEFT JOIN projets p ON b.projet_id = p.id
                    WHERE b.statut = 'En attente de Finance'
                    ORDER BY b.date_soumission ASC";
    $besoins_a_valider = $pdo->query($sql_besoins)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur besoins: " . $e->getMessage());
}

// --- 3. STATISTIQUES GLOBALES ---
$stats = ['en_attente' => 0, 'valides' => 0, 'rejetes' => 0, 'total' => 0];
try {
    $stats['en_attente'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'En attente de Finance'")->fetchColumn();
    $stats['valides'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut NOT IN ('En attente de Finance', 'Rejeté par Finance')")->fetchColumn();
    $stats['rejetes'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut LIKE 'Rejeté%'")->fetchColumn();
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM besoins")->fetchColumn();
} catch (PDOException $e) {}
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
            --swiss-blue: #0056b3; --swiss-blue-light: #007bff;
            --swiss-green: #28a745; --swiss-red: #dc3545; --swiss-orange: #fd7e14;
            --swiss-gray-dark: #495057; --swiss-gray: #6c757d; --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff; --sidebar-width: 260px;
        }
        * { box-sizing: border-box; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background: var(--swiss-white); border-right: 1px solid var(--swiss-gray-light); position: fixed; height: 100vh; left: 0; top: 0; z-index: 1000; transition: transform 0.3s ease; }
        .sidebar-brand { padding: 1.5rem; border-bottom: 1px solid var(--swiss-gray-light); background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%); color: white; }
        .sidebar .nav-link { color: var(--swiss-gray-dark); padding: 0.75rem 1rem; margin: 0.2rem 0.5rem; border-radius: 8px; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: var(--swiss-blue); color: white; }
        .sidebar .nav-link i { width: 20px; margin-right: 0.5rem; }
        
        /* Main & Header */
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
        .main-header { background: var(--swiss-white); border-bottom: 1px solid var(--swiss-gray-light); padding: 1rem 1.5rem; position: sticky; top: 0; z-index: 100; }
        
        /* Cards */
        .dashboard-card { background: var(--swiss-white); border: 1px solid var(--swiss-gray-light); border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; }
        .dashboard-card:hover { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12); transform: translateY(-2px); }
        .card-header { background: var(--swiss-white); border-bottom: 1px solid var(--swiss-gray-light); padding: 1.25rem; }
        
        /* Stats */
        .stat-card { border-radius: 12px; padding: 1.5rem; color: white; text-align: center; transition: transform 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card.pending { background: linear-gradient(135deg, var(--swiss-orange) 0%, #ffc107 100%); }
        .stat-card.approved { background: linear-gradient(135deg, var(--swiss-green) 0%, #20c997 100%); }
        .stat-card.rejected { background: linear-gradient(135deg, var(--swiss-red) 0%, #e83e8c 100%); }
        .stat-card.total { background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%); }
        .stat-number { font-size: 2.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }

        /* Buttons & Badges */
        .btn-swiss { background: var(--swiss-blue); border: none; color: white; padding: 0.5rem 1rem; border-radius: 6px; font-weight: 600; transition: all 0.3s ease; }
        .btn-swiss:hover { background: var(--swiss-blue-light); color: white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3); }
        .badge { font-size: 0.75rem; padding: 0.4rem 0.75rem; border-radius: 6px; }

        /* Notifications Style */
        .notification-dropdown { width: 350px; max-height: 400px; overflow-y: auto; }
        .notification-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0; white-space: normal; transition: background-color 0.2s; }
        .notification-item:hover { background-color: #f8f9fa; }
        .notification-item.unread { background-color: rgba(13, 110, 253, 0.08); } /* Fond bleu clair pour non lu */
        .notification-item.unread .message-text { font-weight: 700; color: #000; }
        .notification-item .message-text { color: #555; }

        /* Responsive */
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.mobile-open { transform: translateX(0); } .main-content { margin-left: 0; } .mobile-menu-btn { display: block; } }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 1.25rem; color: var(--swiss-gray-dark); }
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--swiss-gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
    </style>
</head>
<body>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5 class="mb-1">SWISSCONTACT</h5>
            <small class="opacity-75">Service Finance</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link active" href="finance_dashboard.php"><i class="bi bi-wallet2 me-2"></i>Validation Budget</a></li>
                <li class="nav-item mb-1"><a class="nav-link" href="finance_historique.php"><i class="bi bi-clock-history me-2"></i>Historique</a></li>
            </ul>
        </div>
    </nav>

    <div class="main-content" id="mainContent">
        <header class="main-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button class="mobile-menu-btn me-3" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
                <div>
                    <h2 class="h4 mb-1">Tableau de Bord Finance</h2>
                    <p class="text-muted mb-0 small">Validation budgétaire et suivi des besoins</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3 header-actions">
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
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <li>
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="dropdown-item notification-item <?= $notif['lue'] ? '' : 'unread' ?>">
                                        <div class="message-text small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-primary mt-1" style="font-size: 0.75rem;">
                                            <i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">F</div>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="px-3 py-2">
                            <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="stats-grid fade-in">
                <div class="stat-card pending">
                    <div class="stat-number"><?= $stats['en_attente'] ?></div>
                    <div class="stat-label"><i class="bi bi-clock-history me-1"></i>En Attente</div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-number"><?= $stats['valides'] ?></div>
                    <div class="stat-label"><i class="bi bi-check-circle me-1"></i>Validés</div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-number"><?= $stats['rejetes'] ?></div>
                    <div class="stat-label"><i class="bi bi-x-circle me-1"></i>Rejetés</div>
                </div>
                <div class="stat-card total">
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label"><i class="bi bi-folder me-1"></i>Total Dossiers</div>
                </div>
            </div>

            <div class="dashboard-card fade-in">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-list-check me-2"></i>Besoins en attente de validation</h5>
                    <span class="badge bg-warning text-dark"><?= count($besoins_a_valider) ?> en attente</span>
                </div>
                <div class="card-body">
                    <?php if (empty($besoins_a_valider)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <h5 class="text-muted">Tout est à jour !</h5>
                            <p class="text-muted">Aucun dossier budgétaire en attente.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Titre</th>
                                        <th>Projet</th>
                                        <th>Demandeur</th>
                                        <th>Montant Est.</th>
                                        <th>Date</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($besoins_a_valider as $besoin): ?>
                                        <tr>
                                            <td><code class="bg-light px-2 py-1 rounded border"><?= htmlspecialchars($besoin['id']) ?></code></td>
                                            <td class="fw-bold"><?= htmlspecialchars($besoin['titre']) ?></td>
                                            <td class="text-primary"><?= htmlspecialchars($besoin['nom_projet'] ?? 'Non défini') ?></td>
                                            <td><i class="bi bi-person me-1 text-muted"></i><?= htmlspecialchars($besoin['nom_demandeur']) ?></td>
                                            <td class="fw-bold text-success"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</td>
                                            <td class="small text-muted"><?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></td>
                                            <td class="text-end">
                                                <a href="finance_view_besoin.php?id=<?= htmlspecialchars($besoin['id']) ?>" class="btn btn-swiss btn-sm shadow-sm">
                                                    <i class="bi bi-eye me-1"></i>Examiner
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
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu Mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) document.getElementById('sidebar').classList.remove('mobile-open');
            });
        });

        // --- GESTION NOTIFICATIONS (MISE À JOUR) ---
        function markNotificationsRead() {
            const badge = document.getElementById('notifBadge');
            if (badge) {
                // Envoie l'info au serveur pour enregistrer la date de lecture (NOW())
                fetch('marquer_notifications_lues.php', { method: 'POST' })
                .then(response => {
                    if (response.ok) {
                        badge.remove(); // Supprime le point rouge
                        // Passe visuellement les items en "lus" (gris)
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        // Vérification automatique toutes les 10 secondes (Polling)
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

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) {
                notifDropdown.addEventListener('show.bs.dropdown', markNotificationsRead);
            }
            // Lance le polling toutes les 10 secondes
            setInterval(checkForUpdates, 10000);
        });
    </script>
</body>
</html>