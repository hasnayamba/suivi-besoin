<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') { 
    header('Location: login.php'); 
    exit(); 
}

// --- 1. GESTION DU FILTRE ---
$filtre_partenaire = $_GET['partenaire'] ?? '';
$filtre_antenne = $_GET['antenne'] ?? '';
$filtre_statut = $_GET['statut'] ?? '';

// Construction de la clause WHERE pour le filtre
$where_clauses = [];
$params = [];

if (!empty($filtre_partenaire)) {
    $where_clauses[] = "nom_partenaire LIKE :partenaire";
    $params[':partenaire'] = "%" . $filtre_partenaire . "%";
}

if (!empty($filtre_antenne)) {
    $where_clauses[] = "antenne = :antenne";
    $params[':antenne'] = $filtre_antenne;
}

if (!empty($filtre_statut)) {
    $where_clauses[] = "statut = :statut";
    $params[':statut'] = $filtre_statut;
}

$sql = "SELECT *, antenne FROM conventions";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY date_creation DESC";

// Récupérer les conventions filtrées
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conventions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des conventions : " . $e->getMessage());
    $conventions = [];
    $_SESSION['error'] = "Erreur de base de données : impossible de charger les conventions.";
}

// --- 2. GESTION DE L'ALERTE D'EXPIRATION ---
$conventions_alert = [];
$jours_expiration = 60;
$date_limite = date('Y-m-d', strtotime("+$jours_expiration days"));

foreach ($conventions as $c) {
    if (strtolower($c['statut']) === 'en cours' && $c['date_fin'] <= $date_limite) {
        $conventions_alert[] = $c;
    }
}

// --- 3. STATISTIQUES ---
try {
    $stats_total = $pdo->query("SELECT COUNT(*) FROM conventions")->fetchColumn();
    $stats_en_cours = $pdo->query("SELECT COUNT(*) FROM conventions WHERE statut = 'En cours'")->fetchColumn();
    $stats_termine = $pdo->query("SELECT COUNT(*) FROM conventions WHERE statut = 'Terminé'")->fetchColumn();
    $stats_montant_total = $pdo->query("SELECT SUM(montant_global) FROM conventions WHERE statut = 'En cours'")->fetchColumn();
} catch (PDOException $e) {
    $stats_total = $stats_en_cours = $stats_termine = $stats_montant_total = 0;
}

// --- 4. RÉCUPÉRATION DES VALEURS UNIQUES POUR LES FILTRES ---
$partenaires_uniques = $pdo->query("SELECT DISTINCT nom_partenaire FROM conventions ORDER BY nom_partenaire")->fetchAll(PDO::FETCH_COLUMN);
$antennes_uniques = $pdo->query("SELECT DISTINCT antenne FROM conventions ORDER BY antenne")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion des Conventions | Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --swiss-blue: #0056b3;
            --swiss-blue-light: #007bff;
            --swiss-green: #28a745;
            --swiss-orange: #fd7e14;
            --swiss-purple: #6f42c1;
            --swiss-gray-dark: #495057;
            --swiss-gray: #6c757d;
            --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Header Styles */
        .main-header {
            background: var(--swiss-white);
            border-bottom: 1px solid var(--swiss-gray-light);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        /* Stats Cards */
        .stat-card {
            background: var(--swiss-white);
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            padding: 1.5rem;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--swiss-gray);
            font-weight: 500;
        }
        
        /* Alert Styles */
        .alert-expiration {
            border-left: 4px solid var(--swiss-orange);
            background: rgba(253, 126, 20, 0.05);
        }
        
        /* Table Styles */
        .table-card {
            background: var(--swiss-white);
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--swiss-gray-light);
            border-bottom: 2px solid var(--swiss-gray);
            font-weight: 600;
            color: var(--swiss-gray-dark);
            padding: 1rem 0.75rem;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.04);
        }
        
        /* Badge Styles */
        .badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            font-weight: 500;
        }
        
        /* Button Styles */
        .btn-primary {
            background: var(--swiss-blue);
            border: none;
            border-radius: 8px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--swiss-blue-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3);
        }
        
        /* Filter Card */
        .filter-card {
            background: var(--swiss-white);
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .stat-card {
                padding: 1rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .btn-group .btn {
                padding: 0.25rem 0.5rem;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease;
        }
    </style>
</head>
<body>
<div class="d-flex vh-100">
    <?php include 'sidebar_convention.php'; ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <!-- Header -->
        <header class="main-header px-4 py-3 sticky-top">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 fw-bold">Gestion des Conventions</h2>
                    <p class="text-muted mb-0 small">Suivi et gestion des partenariats institutionnels</p>
                </div>
                <a href="ajouter_convention.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Nouvelle Convention
                </a>
            </div>
        </header>
        
        <main class="p-4">
            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show fade-in" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show fade-in" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Alertes d'expiration -->
            <?php if (!empty($conventions_alert)): ?>
                <div class="alert alert-warning alert-expiration alert-dismissible fade show fade-in" role="alert">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="alert-heading mb-2">
                                <i class="bi bi-clock-history me-1"></i>
                                Attention : <?= count($conventions_alert) ?> convention(s) arrive(nt) à expiration
                            </h6>
                            <div class="row g-2">
                                <?php foreach (array_slice($conventions_alert, 0, 3) as $c_alert): ?>
                                    <div class="col-md-4">
                                        <div class="border-start border-warning border-3 ps-2">
                                            <strong><?= htmlspecialchars($c_alert['num_convention']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($c_alert['nom_partenaire']) ?> 
                                                • Fin : <?= date('d/m/Y', strtotime($c_alert['date_fin'])) ?>
                                            </small>
                                            <br>
                                            <a href="detail_convention.php?id=<?= $c_alert['id'] ?>" class="btn btn-sm btn-outline-warning mt-1">
                                                <i class="bi bi-eye me-1"></i>Voir
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($conventions_alert) > 3): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        + <?= count($conventions_alert) - 3 ?> autre(s) convention(s) concernée(s)
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4 fade-in">
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-primary">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <div class="stat-number text-primary"><?= $stats_total ?></div>
                        <div class="stat-label">Total Conventions</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-success">
                            <i class="bi bi-play-circle"></i>
                        </div>
                        <div class="stat-number text-success"><?= $stats_en_cours ?></div>
                        <div class="stat-label">En Cours</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-secondary">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-number text-secondary"><?= $stats_termine ?></div>
                        <div class="stat-label">Terminées</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-warning">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div class="stat-number text-warning">
                            <?= number_format($stats_montant_total, 0, ',', ' ') ?>
                        </div>
                        <div class="stat-label">Montant Total (FCFA)</div>
                    </div>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="filter-card mb-4 fade-in">
                <div class="card-header bg-white border-bottom-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel me-2"></i>Filtrer les Conventions
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="filtre_partenaire" class="form-label fw-medium">Partenaire</label>
                            <input type="text" class="form-control" id="filtre_partenaire" name="partenaire" 
                                   value="<?= htmlspecialchars($filtre_partenaire) ?>" 
                                   placeholder="Rechercher un partenaire...">
                        </div>

                        <div class="col-md-3">
                            <label for="filtre_antenne" class="form-label fw-medium">Antenne</label>
                            <select class="form-select" id="filtre_antenne" name="antenne">
                                <option value="">Toutes les antennes</option>
                                <?php foreach ($antennes_uniques as $antenne): ?>
                                    <option value="<?= htmlspecialchars($antenne) ?>" <?= $filtre_antenne == $antenne ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($antenne) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label for="filtre_statut" class="form-label fw-medium">Statut</label>
                            <select class="form-select" id="filtre_statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="En cours" <?= $filtre_statut == 'En cours' ? 'selected' : '' ?>>En cours</option>
                                <option value="Terminé" <?= $filtre_statut == 'Terminé' ? 'selected' : '' ?>>Terminé</option>
                                <option value="En attente" <?= $filtre_statut == 'En attente' ? 'selected' : '' ?>>En attente</option>
                            </select>
                        </div>

                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-2"></i>Filtrer
                            </button>
                        </div>
                        
                        <?php if (!empty($filtre_partenaire) || !empty($filtre_antenne) || !empty($filtre_statut)): ?>
                            <div class="col-12">
                                <a href="?" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Réinitialiser
                                </a>
                                <small class="text-muted ms-2">
                                    <?= count($conventions) ?> résultat(s) trouvé(s)
                                </small>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Conventions Table -->
            <div class="table-card fade-in">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check me-2"></i>Liste des Conventions
                    </h5>
                    <span class="badge bg-light text-dark">
                        <?= count($conventions) ?> convention(s)
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="border-0">N° Convention</th>
                                    <th class="border-0">Partenaire</th>
                                    <th class="border-0">Objet</th>
                                    <th class="border-0">Antenne</th>
                                    <th class="border-0 text-end">Montant Global</th>
                                    <th class="border-0 text-end">Solde Restant</th>
                                    <th class="border-0">Date Fin</th>
                                    <th class="border-0">Statut</th>
                                    <th class="border-0 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($conventions)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="bi bi-inbox display-4 mb-3"></i>
                                            <h5>Aucune convention trouvée</h5>
                                            <p class="mb-0">Aucune convention ne correspond à vos critères de recherche.</p>
                                            <?php if (!empty($filtre_partenaire) || !empty($filtre_antenne) || !empty($filtre_statut)): ?>
                                                <a href="?" class="btn btn-primary mt-3">
                                                    <i class="bi bi-arrow-clockwise me-2"></i>Réinitialiser la recherche
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($conventions as $c): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= htmlspecialchars($c['num_convention']) ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-medium"><?= htmlspecialchars($c['nom_partenaire']) ?></div>
                                        </td>
                                        <td>
                                            <span title="<?= htmlspecialchars($c['objet_convention']) ?>">
                                                <?= substr(htmlspecialchars($c['objet_convention']), 0, 30) ?>
                                                <?= strlen($c['objet_convention']) > 30 ? '...' : '' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark">
                                                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($c['antenne']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?= number_format($c['montant_global'], 0, ',', ' ') ?> FCFA
                                        </td>
                                        <td class="text-end">
                                            <span class="fw-bold text-danger">
                                                <?= number_format($c['solde_restant'], 0, ',', ' ') ?> FCFA
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $date_fin = strtotime($c['date_fin']);
                                            $aujourdhui = strtotime('today');
                                            $difference = ($date_fin - $aujourdhui) / (60 * 60 * 24);
                                            
                                            if ($difference < 30 && $c['statut'] == 'En cours') {
                                                echo '<span class="text-danger fw-bold">';
                                            } elseif ($difference < 60 && $c['statut'] == 'En cours') {
                                                echo '<span class="text-warning fw-bold">';
                                            } else {
                                                echo '<span>';
                                            }
                                            echo date('d/m/Y', $date_fin);
                                            echo '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                                $statut_class = [
                                                    'En cours' => 'bg-success',
                                                    'Terminé' => 'bg-secondary',
                                                    'En attente' => 'bg-warning text-dark'
                                                ][$c['statut']] ?? 'bg-light text-dark';
                                            ?>
                                            <span class="badge <?= $statut_class ?>">
                                                <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>
                                                <?= htmlspecialchars($c['statut']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="detail_convention.php?id=<?= htmlspecialchars($c['id']) ?>" 
                                                   class="btn btn-outline-info" 
                                                   title="Voir les détails"
                                                   data-bs-toggle="tooltip">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="modifier_convention.php?id=<?= htmlspecialchars($c['id']) ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Modifier"
                                                   data-bs-toggle="tooltip">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </div>
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
        // Activer les tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Auto-submit du formulaire de filtre quand les selects changent
        document.getElementById('filtre_antenne').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('filtre_statut').addEventListener('change', function() {
            this.form.submit();
        });
    });
</script>
</body>
</html>