<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') { 
    header('Location: login.php'); 
    exit(); 
}

// --- 1. MISE À JOUR AUTOMATIQUE DES STATUTS (Base de données) ---
try {
    $pdo->query("UPDATE conventions 
                 SET statut = 'Expiré' 
                 WHERE date_fin < CURDATE() 
                 AND statut = 'En cours'");
} catch (PDOException $e) {
    error_log("Erreur MAJ automatique conventions : " . $e->getMessage());
}

// --- 2. GESTION DU FILTRE ---
$filtre_partenaire = $_GET['partenaire'] ?? '';
$filtre_antenne = $_GET['antenne'] ?? '';
$filtre_statut = $_GET['statut'] ?? '';

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

$sql = "SELECT * FROM conventions";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY date_creation DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conventions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur BD : " . $e->getMessage());
    $conventions = [];
}

// --- 3. GESTION DES ALERTES (Avec vérification temps réel) ---
$conventions_alert = [];
$aujourdhui = strtotime('today');
$date_limite = strtotime('+60 days');

foreach ($conventions as $c) {
    $dateFinTimestamp = !empty($c['date_fin']) ? strtotime($c['date_fin']) : 0;
    $statut_reel = $c['statut'];

    // FORÇAGE TEMPS RÉEL : Si la date est passée, on le considère comme expiré
    if ($statut_reel === 'En cours' && $dateFinTimestamp > 0 && $dateFinTimestamp < $aujourdhui) {
        $statut_reel = 'Expiré';
    }

    if ($statut_reel === 'Expiré' || ($statut_reel === 'En cours' && $dateFinTimestamp > 0 && $dateFinTimestamp <= $date_limite)) {
        // On sauvegarde le statut corrigé pour l'affichage des alertes
        $c['statut_calcule'] = $statut_reel;
        $conventions_alert[] = $c;
    }
}

// --- 4. STATISTIQUES ---
try {
    $stats_total = $pdo->query("SELECT COUNT(*) FROM conventions")->fetchColumn();
    $stats_en_cours = $pdo->query("SELECT COUNT(*) FROM conventions WHERE statut = 'En cours' AND date_fin >= CURDATE()")->fetchColumn();
    $stats_termine = $pdo->query("SELECT COUNT(*) FROM conventions WHERE statut IN ('Terminé', 'Expiré') OR (statut = 'En cours' AND date_fin < CURDATE())")->fetchColumn();
    $stats_montant_total = $pdo->query("SELECT SUM(montant_global) FROM conventions WHERE statut = 'En cours'")->fetchColumn();
} catch (PDOException $e) {
    $stats_total = $stats_en_cours = $stats_termine = $stats_montant_total = 0;
}

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
            --swiss-blue: #0056b3; --swiss-blue-light: #007bff; --swiss-green: #28a745;
            --swiss-orange: #fd7e14; --swiss-gray-dark: #495057; --swiss-gray: #6c757d;
            --swiss-gray-light: #e9ecef; --swiss-white: #ffffff;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-header { background: var(--swiss-white); border-bottom: 1px solid var(--swiss-gray-light); box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); }
        .stat-card { background: var(--swiss-white); border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); transition: all 0.3s ease; padding: 1.5rem; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12); }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; margin-bottom: 1rem; }
        .stat-number { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.9rem; color: var(--swiss-gray); font-weight: 500; }
        .alert-expiration { border-left: 4px solid var(--swiss-orange); background: rgba(253, 126, 20, 0.05); }
        .table-card { background: var(--swiss-white); border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); overflow: hidden; }
        .table th { background-color: var(--swiss-gray-light); border-bottom: 2px solid var(--swiss-gray); font-weight: 600; color: var(--swiss-gray-dark); padding: 1rem 0.75rem; }
        .table td { padding: 1rem 0.75rem; vertical-align: middle; }
        .table-hover tbody tr:hover { background-color: rgba(0, 123, 255, 0.04); }
        .bg-expired { background-color: #fff5f5 !important; }
        .btn-primary { background: var(--swiss-blue); border: none; border-radius: 8px; font-weight: 600; padding: 0.75rem 1.5rem; transition: all 0.3s ease; }
        .btn-primary:hover { background: var(--swiss-blue-light); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3); }
        .filter-card { background: var(--swiss-white); border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease; }
    </style>
</head>
<body>
<div class="d-flex vh-100">
    <?php include 'sidebar_convention.php'; ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show fade-in"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show fade-in"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if (!empty($conventions_alert)): ?>
                <div class="alert alert-warning alert-expiration alert-dismissible fade show fade-in" role="alert">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 mt-1 text-danger"></i>
                        <div class="flex-grow-1">
                            <h6 class="alert-heading mb-2 fw-bold text-danger">
                                ACTIONS REQUISES : <?= count($conventions_alert) ?> convention(s) à traiter
                            </h6>
                            <div class="row g-2">
                                <?php foreach (array_slice($conventions_alert, 0, 3) as $c_alert): 
                                    $estExpire = ($c_alert['statut_calcule'] === 'Expiré');
                                ?>
                                    <div class="col-md-4">
                                        <div class="border-start border-<?= $estExpire ? 'danger' : 'warning' ?> border-3 ps-2">
                                            <strong><?= htmlspecialchars($c_alert['num_convention']) ?></strong><br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($c_alert['nom_partenaire']) ?> 
                                                • Fin : <?= !empty($c_alert['date_fin']) ? date('d/m/Y', strtotime($c_alert['date_fin'])) : 'N/A' ?>
                                            </small><br>
                                            <span class="badge <?= $estExpire ? 'bg-danger' : 'bg-warning text-dark' ?> mt-1">
                                                <?= $estExpire ? 'Délai dépassé' : 'Échéance proche' ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row mb-4 fade-in">
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-primary"><i class="bi bi-file-text"></i></div>
                        <div class="stat-number text-primary"><?= $stats_total ?></div>
                        <div class="stat-label">Total Conventions</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-success"><i class="bi bi-play-circle"></i></div>
                        <div class="stat-number text-success"><?= $stats_en_cours ?></div>
                        <div class="stat-label">Actives (En Cours)</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-secondary"><i class="bi bi-check-all"></i></div>
                        <div class="stat-number text-secondary"><?= $stats_termine ?></div>
                        <div class="stat-label">Terminées / Expirées</div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card h-100">
                        <div class="stat-icon bg-warning"><i class="bi bi-currency-exchange"></i></div>
                        <div class="stat-number text-warning"><?= number_format((float)$stats_montant_total, 0, ',', ' ') ?></div>
                        <div class="stat-label">Montant Actif (FCFA)</div>
                    </div>
                </div>
            </div>

            <div class="filter-card mb-4 fade-in">
                <div class="card-header bg-white border-bottom-0"><h5 class="card-title mb-0"><i class="bi bi-funnel me-2"></i>Filtrer les Conventions</h5></div>
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Partenaire</label>
                            <input type="text" class="form-control" name="partenaire" value="<?= htmlspecialchars($filtre_partenaire) ?>" placeholder="Rechercher...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Antenne</label>
                            <select class="form-select" id="filtre_antenne" name="antenne">
                                <option value="">Toutes les antennes</option>
                                <?php foreach ($antennes_uniques as $antenne): ?>
                                    <option value="<?= htmlspecialchars($antenne) ?>" <?= $filtre_antenne == $antenne ? 'selected' : '' ?>><?= htmlspecialchars($antenne) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Statut</label>
                            <select class="form-select" id="filtre_statut" name="statut">
                                <option value="">Tous les statuts</option>
                                <option value="En cours" <?= $filtre_statut == 'En cours' ? 'selected' : '' ?>>En cours</option>
                                <option value="Expiré" <?= $filtre_statut == 'Expiré' ? 'selected' : '' ?>>Expiré</option>
                                <option value="Terminé" <?= $filtre_statut == 'Terminé' ? 'selected' : '' ?>>Terminé</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-2"></i>Filtrer</button>
                        </div>
                    </form>
                </div>
            </div>

           <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center no-print">
    <h5 class="card-title mb-0"><i class="bi bi-list-check me-2"></i>Liste des Conventions</h5>
    
    <div class="d-flex align-items-center gap-3">
        <span class="badge bg-light text-dark border"><?= count($conventions) ?> convention(s)</span>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm shadow-sm">
            <i class="bi bi-printer me-1"></i> Imprimer la liste
        </button>
    </div>
</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="border-0 ps-4">N° Conv.</th>
                                    <th class="border-0">Partenaire</th>
                                    <th class="border-0">Objet</th>
                                    <th class="border-0">Antenne</th>
                                    <th class="border-0 text-end">Montant Global</th>
                                    <th class="border-0 text-end">Solde Restant</th>
                                    <th class="border-0">Date Fin</th>
                                    <th class="border-0 text-center">Statut</th>
                                    <th class="border-0 text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($conventions)): ?>
                                <tr><td colspan="9" class="text-center py-5 text-muted"><i class="bi bi-inbox display-4 d-block mb-3"></i><h5>Aucune convention trouvée</h5></td></tr>
                            <?php else: ?>
                                <?php foreach ($conventions as $c): 
                                    // --- LE CŒUR DU FORÇAGE TEMPS RÉEL ---
                                    $dateFinTimestamp = !empty($c['date_fin']) ? strtotime($c['date_fin']) : 0;
                                    $aujourdhuiTimestamp = strtotime('today');
                                    
                                    $statut_affichage = $c['statut'];
                                    // Si on a passé la date de fin, on FORCE l'affichage à "Expiré"
                                    if ($statut_affichage === 'En cours' && $dateFinTimestamp > 0 && $dateFinTimestamp < $aujourdhuiTimestamp) {
                                        $statut_affichage = 'Expiré';
                                    }

                                    $estExpire = ($statut_affichage === 'Expiré');
                                ?>
                                    <tr class="<?= $estExpire ? 'bg-expired' : '' ?>">
                                        <td class="ps-4"><strong class="text-primary"><?= htmlspecialchars($c['num_convention']) ?></strong></td>
                                        <td><div class="fw-medium"><?= htmlspecialchars($c['nom_partenaire']) ?></div></td>
                                        <td><span title="<?= htmlspecialchars($c['objet_convention']) ?>"><?= substr(htmlspecialchars($c['objet_convention']), 0, 30) ?><?= strlen($c['objet_convention']) > 30 ? '...' : '' ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($c['antenne']) ?></span></td>
                                        
                                        <td class="text-end fw-bold"><?= number_format((float)($c['montant_global'] ?? 0), 0, ',', ' ') ?></td>
                                        <td class="text-end"><span class="fw-bold text-danger"><?= number_format((float)($c['solde_restant'] ?? 0), 0, ',', ' ') ?></span></td>
                                        
                                        <td data-sort="<?= htmlspecialchars($c['date_fin'] ?? '0000-00-00') ?>">
                                            <?php
                                            if ($dateFinTimestamp > 0) {
                                                $difference = ($dateFinTimestamp - $aujourdhuiTimestamp) / (60 * 60 * 24);
                                                if ($difference < 0 || $statut_affichage === 'Expiré') {
                                                    echo '<span class="text-danger fw-bold">';
                                                } elseif ($difference < 60 && $statut_affichage === 'En cours') {
                                                    echo '<span class="text-warning fw-bold">';
                                                } else {
                                                    echo '<span class="text-muted">';
                                                }
                                                echo date('d/m/Y', $dateFinTimestamp) . '</span>';
                                            } else {
                                                echo '<span class="text-muted">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        
                                        <td class="text-center">
                                            <?php
                                                $badgeClass = match($statut_affichage) {
                                                    'Expiré' => 'bg-danger',
                                                    'Terminé' => 'bg-secondary',
                                                    'En cours' => 'bg-success',
                                                    'En attente' => 'bg-warning text-dark',
                                                    default => 'bg-primary'
                                                };
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($statut_affichage) ?></span>
                                        </td>
                                        
                                        <td class="text-end pe-4">
                                            <div class="btn-group btn-group-sm">
                                                <a href="detail_convention.php?id=<?= $c['id'] ?>" class="btn btn-outline-info" title="Consulter"><i class="bi bi-eye"></i></a>
                                                
                                                <?php if(in_array($statut_affichage, ['En cours', 'En attente'])): ?>
                                                    <a href="modifier_convention.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                                <?php endif; ?>

                                                <?php 
                                                    $estEligibleAction = false;
                                                    if ($statut_affichage === 'Expiré') {
                                                        $estEligibleAction = true;
                                                    } elseif ($statut_affichage === 'En cours' && $dateFinTimestamp > 0) {
                                                        $limiteAlerte = strtotime('+60 days');
                                                        if ($dateFinTimestamp <= $limiteAlerte) {
                                                            $estEligibleAction = true;
                                                        }
                                                    }
                                                    
                                                    if($estEligibleAction): 
                                                ?>
                                                    <a href="renouveler_convention.php?id=<?= $c['id'] ?>" class="btn btn-outline-warning" title="Renouveler"><i class="bi bi-arrow-repeat"></i></a>
                                                    <a href="terminer_convention.php?id=<?= $c['id'] ?>" class="btn btn-outline-danger" title="Terminer"><i class="bi bi-door-closed"></i></a>
                                                <?php endif; ?>
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

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // 1. Initialisation du DataTable (Essentiel pour le tri et l'impression)
    $('.table').DataTable({
        "order": [[ 6, "asc" ]], // Tri par date de fin
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json" },
        "pageLength": 10,
        "responsive": true
    });

    // 2. Activation des Tooltips (Bulles d'info au survol des boutons)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // 3. Auto-soumission des filtres
    document.getElementById('filtre_antenne').addEventListener('change', function() { this.form.submit(); });
    document.getElementById('filtre_statut').addEventListener('change', function() { this.form.submit(); });
});
</script>
</body>
</html>