<?php
session_start();
include 'db_connect.php';

// Sécurité accès finance
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'finance') {
    header('Location: login.php');
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Service Finance';
$utilisateur_email = $_SESSION['user_email'] ?? 'finance@example.com';

// Gestion des filtres
$filtre_statut = $_GET['statut'] ?? 'tous';
$filtre_mois = $_GET['mois'] ?? '';
$filtre_annee = $_GET['annee'] ?? date('Y');

// Construction de la requête avec filtres
$sql = "SELECT b.*, u.nom AS nom_demandeur 
        FROM besoins b 
        LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
        WHERE b.statut != 'En attente de Finance'";

$params = [];

if ($filtre_statut !== 'tous') {
    $sql .= " AND b.statut = ?";
    $params[] = $filtre_statut;
}

if (!empty($filtre_mois) && !empty($filtre_annee)) {
    $sql .= " AND MONTH(b.date_soumission) = ? AND YEAR(b.date_soumission) = ?";
    $params[] = $filtre_mois;
    $params[] = $filtre_annee;
}

$sql .= " ORDER BY b.date_soumission DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$besoins_traites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les années disponibles pour le filtre
$annees = $pdo->query("SELECT DISTINCT YEAR(date_soumission) as annee FROM besoins ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);

// Statistiques pour les filtres
$stats_filtres = [
    'tous' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut != 'En attente de Finance'")->fetchColumn(),
    'Validé' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Validé'")->fetchColumn(),
    'Rejeté par Finance' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Rejeté par Finance'")->fetchColumn(),
    'Rejeté par Comptable' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Rejeté par Comptable'")->fetchColumn(),
    'Correction Requise' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Correction Requise'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Besoins | Swisscontact Finance</title>
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
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: var(--swiss-white);
            border-right: 1px solid var(--swiss-gray-light);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
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
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        
        .main-header {
            background: var(--swiss-white);
            border-bottom: 1px solid var(--swiss-gray-light);
            padding: 1rem 1.5rem;
        }
        
        .dashboard-card {
            background: var(--swiss-white);
            border: 1px solid var(--swiss-gray-light);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .filter-badge {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-badge:hover {
            transform: scale(1.05);
        }
        
        .filter-badge.active {
            background: var(--swiss-blue) !important;
            color: white !important;
        }
        
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5 class="mb-1"></i>SWISSCONTACT</h5>
            <small class="opacity-75">Service Finance</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1">
                    <a class="nav-link" href="finance_dashboard.php">
                        <i class="bi bi-wallet2 me-2"></i>Validation Budget
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link active" href="finance_historique.php">
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
                    <h2 class="h4 mb-1">Historique des Besoins Traités</h2>
                    <p class="text-muted mb-0 small">Consultation et analyse des décisions passées</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2"></i>
                        <span><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="deconnexion.php">Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-4">
            <!-- Filtres -->
            <div class="dashboard-card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-funnel me-2"></i>Filtres</h5>
                </div>
                <div class="card-body">
                    <!-- Filtre par statut -->
                    <div class="mb-3">
                        <label class="form-label fw-medium">Statut :</label>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="filter-badge badge <?= $filtre_statut === 'tous' ? 'bg-primary active' : 'bg-light text-dark' ?>" 
                                  onclick="window.location.href='?statut=tous&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Tous (<?= $stats_filtres['tous'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Validé' ? 'bg-success active' : 'bg-light text-dark' ?>" 
                                  onclick="window.location.href='?statut=Validé&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Validés (<?= $stats_filtres['Validé'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Rejeté par Finance' ? 'bg-danger active' : 'bg-light text-dark' ?>" 
                                  onclick="window.location.href='?statut=Rejeté par Finance&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Rejetés Finance (<?= $stats_filtres['Rejeté par Finance'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Rejeté par Comptable' ? 'bg-danger active' : 'bg-light text-dark' ?>" 
                                  onclick="window.location.href='?statut=Rejeté par Comptable&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Rejetés Comptable (<?= $stats_filtres['Rejeté par Comptable'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Correction Requise' ? 'bg-warning active text-dark' : 'bg-light text-dark' ?>" 
                                  onclick="window.location.href='?statut=Correction Requise&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Corrections (<?= $stats_filtres['Correction Requise'] ?>)
                            </span>
                        </div>
                    </div>
                    
                    <!-- Filtre par période -->
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="statut" value="<?= $filtre_statut ?>">
                        <div class="col-md-3">
                            <label for="mois" class="form-label">Mois</label>
                            <select class="form-select" id="mois" name="mois">
                                <option value="">Tous les mois</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $filtre_mois == $i ? 'selected' : '' ?>>
                                        <?= DateTime::createFromFormat('!m', $i)->format('F') ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="annee" class="form-label">Année</label>
                            <select class="form-select" id="annee" name="annee">
                                <?php foreach($annees as $annee): ?>
                                    <option value="<?= $annee ?>" <?= $filtre_annee == $annee ? 'selected' : '' ?>>
                                        <?= $annee ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-filter"></i> Appliquer
                            </button>
                            <a href="finance_historique.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Réinitialiser
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tableau des besoins traités -->
            <div class="dashboard-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check me-2"></i>Besoins Traités
                        <span class="badge bg-secondary ms-2"><?= count($besoins_traites) ?></span>
                    </h5>
                    <div>
                        <small class="text-muted">
                            Dernière mise à jour : <?= date('d/m/Y H:i') ?>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($besoins_traites)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">Aucun besoin trouvé</h4>
                            <p class="text-muted">Aucun besoin ne correspond à vos critères de filtrage.</p>
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
                                        <th>Date Traitement</th>
                                        <th>Statut</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($besoins_traites as $besoin): ?>
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
                                                    <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('d/m/Y', strtotime($besoin['date_traitement'] ?? $besoin['date_soumission'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = 'bg-secondary';
                                                if ($besoin['statut'] === 'Validé') $badge_class = 'bg-success';
                                                elseif (strpos($besoin['statut'], 'Rejeté') !== false) $badge_class = 'bg-danger';
                                                elseif ($besoin['statut'] === 'Correction Requise') $badge_class = 'bg-warning text-dark';
                                                ?>
                                                <span class="badge <?= $badge_class ?>">
                                                    <?= htmlspecialchars($besoin['statut']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="finance_view_besoin.php?id=<?= htmlspecialchars($besoin['id']) ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye me-1"></i> Voir
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
        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Auto-submit form on select change
        document.getElementById('mois').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('annee').addEventListener('change', function() {
            this.form.submit();
        });
    </script>
</body>
</html>