<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- 1. MISE À JOUR AUTOMATIQUE DES STATUTS ---
try {
    $pdo->query("UPDATE contrats 
                 SET statut = 'Expiré' 
                 WHERE date_fin_prevue < CURDATE() 
                 AND statut = 'En cours'");
} catch (PDOException $e) {
    error_log("Erreur MAJ automatique : " . $e->getMessage());
}

// --- LOGIQUE NOTIFICATIONS ---
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

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Logisticien';

// --- FONCTIONS MÉTIERS ---
function get_contrat_metrics($pdo) {
    $metrics = ['contrats_en_cours' => 0, 'contrats_expires' => 0, 'montant_total_ht' => 0];
    try {
        $sql = "SELECT 
                    SUM(CASE WHEN statut = 'En cours' THEN 1 ELSE 0 END) AS contrats_en_cours,
                    SUM(CASE WHEN statut = 'Expiré' THEN 1 ELSE 0 END) AS contrats_expires,
                    SUM(CASE WHEN statut = 'En cours' THEN montant_ht ELSE 0 END) AS montant_total_ht
                FROM contrats";
                
        $result = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $metrics['contrats_en_cours'] = $result['contrats_en_cours'] ?? 0;
            $metrics['contrats_expires'] = $result['contrats_expires'] ?? 0;
            $metrics['montant_total_ht'] = $result['montant_total_ht'] ?? 0;
        }
    } catch (PDOException $e) { error_log("Erreur métriques: " . $e->getMessage()); }
    return $metrics;
}

function get_contrats_alerte($pdo) {
    try {
        $sql = "SELECT * FROM contrats 
                WHERE (statut = 'En cours' AND date_fin_prevue IS NOT NULL AND date_fin_prevue <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))
                OR (statut = 'Expiré')
                ORDER BY date_fin_prevue ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

function get_list_antennes($pdo) {
    try {
        return $pdo->query("SELECT DISTINCT antenne FROM contrats WHERE antenne IS NOT NULL AND antenne != '' ORDER BY antenne ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { return []; }
}

function get_contrats_filtres_datatable($pdo, $antenne = null, $statut = 'all') {
    $sql = "SELECT *, (montant_max_annuel - paiement_effectue) AS solde_restant FROM contrats WHERE 1=1";
    $params = [];
    if (!empty($antenne)) { $sql .= " AND antenne = ?"; $params[] = $antenne; }
    if ($statut !== 'all') { $sql .= " AND statut = ?"; $params[] = $statut; }
    $sql .= " ORDER BY date_creation DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

// --- INITIALISATION ---
$metrics = get_contrat_metrics($pdo);
$liste_antennes = get_list_antennes($pdo);
$filtre_antenne = $_GET['antenne'] ?? '';
$filtre_statut = $_GET['statut'] ?? 'all'; 

$contrats_alerte = get_contrats_alerte($pdo);
$total_alertes = count($contrats_alerte);
$contrats = get_contrats_filtres_datatable($pdo, $filtre_antenne, $filtre_statut);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Contrats</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; }
        .alert-scroll-container { max-height: 400px; overflow-y: auto; }
        .alert-scroll-container thead th { position: sticky; top: 0; z-index: 10; background-color: #dc3545; color: white; }
        .bg-expired { background-color: #fff5f5 !important; }
        .action-btn-group .btn { padding: 0.25rem 0.5rem; }

        @media print {
            .sidebar, header, form, .action-btn-group, .btn { display: none !important; }
            .dataTables_wrapper .row:first-child, .dataTables_wrapper .row:last-child { display: none !important; }
            .d-flex.vh-100 { display: block !important; height: auto !important; }
            .card { border: none !important; box-shadow: none !important; }
            table td:last-child, table th:last-child { display: none !important; }
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <div class="sidebar no-print">
        <?php include 'sidebar_contrat.php'; ?> 
    </div>

    <div class="flex-fill d-flex flex-column overflow-hidden">
        <header class="bg-white border-bottom px-4 py-3 no-print">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-0 fw-bold">Gestion des Contrats</h2>
                    <small class="text-muted">Interface logistique</small>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="width: 300px;">
                            <li class="dropdown-header">Notifications</li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li class="px-3 py-2 border-bottom <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark small d-block"><?= htmlspecialchars($notif['message']) ?></a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i><span class="ms-2"><?= htmlspecialchars($utilisateur_nom) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><a class="dropdown-item text-danger" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            
            <div class="row g-3 mb-4 no-print">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3 border-start border-primary border-4">
                        <h6 class="text-muted small text-uppercase fw-bold">Contrats En Cours</h6>
                        <h3 class="mb-0 text-primary"><?= $metrics['contrats_en_cours'] ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3 border-start border-danger border-4">
                        <h6 class="text-muted small text-uppercase fw-bold">Contrats Expirés</h6>
                        <h3 class="mb-0 text-danger"><?= $metrics['contrats_expires'] ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-success text-white p-3 border-start border-light border-4">
                        <h6 class="text-white-50 small text-uppercase fw-bold">Montant Engagé (HT)</h6>
                        <h3 class="mb-0"><?= number_format((float)$metrics['montant_total_ht'], 0, ',', ' ') ?> <small>CFA</small></h3>
                    </div>
                </div>
            </div>

            <?php if ($total_alertes > 0): ?>
            <div class="card border-danger shadow-sm mb-4 no-print">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i> ACTIONS REQUISES</span>
                    <span class="badge bg-white text-danger"><?= $total_alertes ?> alerte(s)</span>
                </div>
                <div class="card-body p-0 alert-scroll-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Réf/Mandat</th>
                                <th>Fournisseur</th>
                                <th>Date Fin</th>
                                <th>État</th>
                                <th class="text-end pe-3">Actions rapides</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contrats_alerte as $c): 
                                $jours = 0;
                                $estExpire = ($c['statut'] === 'Expiré');
                                if (!empty($c['date_fin_prevue'])) {
                                    $dateFin = new DateTime($c['date_fin_prevue']);
                                    $aujourdhui = new DateTime();
                                    $estExpire = ($dateFin < $aujourdhui || $c['statut'] === 'Expiré');
                                    $jours = $aujourdhui->diff($dateFin)->days;
                                }
                            ?>
                            <tr class="<?= $estExpire ? 'bg-expired' : '' ?>">
                                <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($c['num_contrat'] ?? $c['num_mandat']) ?></td>
                                <td><?= htmlspecialchars($c['nom_fournisseur']) ?></td>
                                <td class="<?= $estExpire ? 'text-danger fw-bold' : '' ?>">
                                    <?= !empty($c['date_fin_prevue']) ? date('d/m/Y', strtotime($c['date_fin_prevue'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <?php if ($estExpire): ?>
                                        <span class="badge bg-dark">Expiré <?= $jours > 0 ? "depuis $jours j" : "" ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Expire dans <?= $jours ?> j</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group action-btn-group">
                                        <a href="voir_contrat.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info" title="Consulter"><i class="bi bi-eye"></i></a>
                                        
                                        <?php if($c['statut'] === 'En cours'): ?>
                                            <a href="modifier_contrat.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                        <?php endif; ?>
                                        
                                        <a href="renouveler_contrat.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning" title="Renouveler"><i class="bi bi-arrow-repeat"></i></a>
                                        <a href="cloturer_contrat.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" title="Clôturer"><i class="bi bi-door-closed"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 fw-bold">Registre des Contrats</h5>
                        
                        <div class="d-flex gap-2 no-print">
                            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Imprimer la liste</button>
                            <a href="ajouter_contrat.php" class="btn btn-primary btn-sm shadow-sm"><i class="bi bi-plus-lg me-1"></i> Nouveau</a>
                        </div>
                    </div>
                    <form method="GET" class="row g-2 no-print">
                        <div class="col-md-4">
                            <select class="form-select form-select-sm" name="antenne">
                                <option value="">Toutes les Antennes</option>
                                <?php foreach ($liste_antennes as $ant): ?>
                                    <option value="<?= htmlspecialchars($ant) ?>" <?= $filtre_antenne === $ant ? 'selected' : '' ?>><?= htmlspecialchars($ant) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" name="statut">
                                <option value="all" <?= $filtre_statut == 'all' ? 'selected' : '' ?>>Tous les Statuts</option>
                                <option value="En cours" <?= $filtre_statut == 'En cours' ? 'selected' : '' ?>>En Cours</option>
                                <option value="Expiré" <?= $filtre_statut == 'Expiré' ? 'selected' : '' ?>>Expiré</option>
                                <option value="Cloturé" <?= $filtre_statut == 'Cloturé' ? 'selected' : '' ?>>Cloturé</option>
                                <option value="Rupture de contrat" <?= $filtre_statut == 'Rupture de contrat' ? 'selected' : '' ?>>Rupture de contrat</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-sm btn-dark w-100">Filtrer les résultats</button>
                        </div>
                    </form>
                </div>
                <div class="card-body">
                    <table id="tableContrats" class="table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Mandat</th>
                                <th>Antenne</th>
                                <th>Fournisseur</th>
                                <th>Montant</th>
                                <th>Échéance</th>
                                <th>Statut</th>
                                <th class="text-end no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contrats as $row): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($row['num_mandat'] ?? '-') ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['antenne']) ?></span></td>
                                    <td><?= htmlspecialchars($row['nom_fournisseur']) ?></td>
                                    
                                    <td><?= number_format((float)($row['montant_ht'] ?? 0), 0, ',', ' ') ?></td>
                                    
                                    <td data-sort="<?= htmlspecialchars($row['date_fin_prevue'] ?? '0000-00-00') ?>">
                                        <?= !empty($row['date_fin_prevue']) ? date('d/m/Y', strtotime($row['date_fin_prevue'])) : '<span class="text-muted small">N/A</span>' ?>
                                    </td>
                                    
                                    <td>
                                        <?php 
                                            $badgeClass = match($row['statut']) {
                                                'Expiré' => 'bg-danger',
                                                'Cloturé' => 'bg-secondary',
                                                'Rupture de contrat' => 'bg-dark',
                                                'En cours' => 'bg-success',
                                                default => 'bg-primary'
                                            };
                                            echo "<span class='badge $badgeClass'>{$row['statut']}</span>";
                                        ?>
                                    </td>
                                    
                                    <td class="text-end no-print">
                                        <div class="btn-group action-btn-group">
                                            <a href="voir_contrat.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" title="Voir fiche"><i class="bi bi-eye"></i></a>
                                            
                                            <?php if($row['statut'] === 'En cours'): ?>
                                                <a href="modifier_contrat.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                            <?php endif; ?>
                                            
                                            <?php 
                                                if(!in_array($row['statut'], ['Cloturé', 'Rupture de contrat'])): 
                                                    $estEligibleAction = false;
                                                    if ($row['statut'] === 'Expiré') {
                                                        $estEligibleAction = true;
                                                    } elseif ($row['statut'] === 'En cours' && !empty($row['date_fin_prevue'])) {
                                                        $dateFin = new DateTime($row['date_fin_prevue']);
                                                        $limiteAlerte = new DateTime('+60 days');
                                                        if ($dateFin <= $limiteAlerte) { $estEligibleAction = true; }
                                                    }
                                                    
                                                    if($estEligibleAction): 
                                            ?>
                                                <a href="renouveler_contrat.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning" title="Renouveler"><i class="bi bi-arrow-repeat"></i></a>
                                                <a href="cloturer_contrat.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" title="Clôturer"><i class="bi bi-door-closed"></i></a>
                                            <?php 
                                                    endif; 
                                                endif; 
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
    $('#tableContrats').DataTable({
        "order": [[ 4, "asc" ]], // Tri par date d'échéance par défaut
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json" },
        "pageLength": 10,
        "responsive": true
    });
});
</script>

</body>
</html>