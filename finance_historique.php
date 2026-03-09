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

// ==================================================================
// CONFIGURATION DE LA PAGINATION
// ==================================================================
$limite_par_page = 10; // Nombre de dossiers à afficher par page
$page_actuelle = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page_actuelle < 1) $page_actuelle = 1;
$offset = ($page_actuelle - 1) * $limite_par_page;

// Traduction des mois en Français
$mois_fr = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// ==================================================================
// 1. CONSTRUCTION DE LA REQUÊTE DE BASE ET DES FILTRES
// ==================================================================
$conditions = "WHERE b.statut != 'En attente de Finance'";
$params = [];

if ($filtre_statut === 'Approuvés') {
    $conditions .= " AND b.statut NOT IN ('En attente de Finance', 'Rejeté par Finance', 'Correction Requise')";
} elseif ($filtre_statut !== 'tous') {
    $conditions .= " AND b.statut LIKE ?";
    $params[] = '%' . $filtre_statut . '%'; 
}

if (!empty($filtre_mois) && !empty($filtre_annee)) {
    $conditions .= " AND MONTH(b.date_soumission) = ? AND YEAR(b.date_soumission) = ?";
    $params[] = $filtre_mois;
    $params[] = $filtre_annee;
}

// ==================================================================
// 2. COMPTER LE NOMBRE TOTAL DE RÉSULTATS POUR LA PAGINATION
// ==================================================================
$sql_count = "SELECT COUNT(*) FROM besoins b $conditions";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_resultats = $stmt_count->fetchColumn();

// Calcul du nombre total de pages
$total_pages = ceil($total_resultats / $limite_par_page);

// ==================================================================
// 3. REQUÊTE PRINCIPALE AVEC LIMIT ET OFFSET
// ==================================================================
$sql = "SELECT b.*, u.nom AS nom_demandeur 
        FROM besoins b 
        LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
        $conditions 
        ORDER BY b.date_soumission DESC 
        LIMIT " . (int)$limite_par_page . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$besoins_traites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les années disponibles pour le filtre
$annees = $pdo->query("SELECT DISTINCT YEAR(date_soumission) as annee FROM besoins ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);

// ==================================================================
// 4. STATISTIQUES GLOBALES
// ==================================================================
$stats_filtres = [
    'tous' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut != 'En attente de Finance'")->fetchColumn(),
    'Approuvés' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut NOT IN ('En attente de Finance', 'Rejeté par Finance', 'Correction Requise')")->fetchColumn(),
    'Rejeté' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut LIKE '%Rejeté%'")->fetchColumn(),
    'Correction' => $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut LIKE '%Correction%'")->fetchColumn(),
];

// Fonction pour conserver les paramètres d'URL (filtres) lors du changement de page
function getUrlFiltres($page) {
    $query = $_GET;
    $query['page'] = $page; // Met à jour uniquement le numéro de page
    return '?' . http_build_query($query);
}
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
            --swiss-gray-dark: #495057;
            --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff;
            --sidebar-width: 260px;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { width: var(--sidebar-width); background: var(--swiss-white); border-right: 1px solid var(--swiss-gray-light); position: fixed; height: 100vh; left: 0; top: 0; z-index: 1000; }
        .sidebar-brand { padding: 1.5rem; border-bottom: 1px solid var(--swiss-gray-light); background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%); color: white; }
        .sidebar .nav-link { color: var(--swiss-gray-dark); padding: 0.75rem 1rem; margin: 0.2rem 0.5rem; border-radius: 8px; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: var(--swiss-blue); color: white; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .main-header { background: var(--swiss-white); border-bottom: 1px solid var(--swiss-gray-light); padding: 1rem 1.5rem; }
        .dashboard-card { background: var(--swiss-white); border: 1px solid var(--swiss-gray-light); border-radius: 12px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); }
        .filter-badge { cursor: pointer; transition: all 0.3s ease; }
        .filter-badge:hover { transform: scale(1.05); }
        .filter-badge.active { background: var(--swiss-blue) !important; color: white !important; }
        
        /* Personnalisation Pagination */
        .page-link { color: var(--swiss-blue); }
        .page-item.active .page-link { background-color: var(--swiss-blue); border-color: var(--swiss-blue); }
        
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-menu-btn { display: block; }
        }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 1.25rem; color: var(--swiss-gray-dark); }
        @media (max-width: 992px) { .mobile-menu-btn { display: block; } }
    </style>
</head>
<body>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h5 class="mb-1"><i class="bi bi-bank me-2"></i>SWISSCONTACT</h5>
            <small class="opacity-75">Service Finance</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link" href="finance_dashboard.php"><i class="bi bi-wallet2 me-2"></i>Validation Budget</a></li>
                <li class="nav-item mb-1"><a class="nav-link active" href="finance_historique.php"><i class="bi bi-clock-history me-2"></i>Historique</a></li>
            </ul>
        </div>
    </nav>

    <div class="main-content" id="mainContent">
        <header class="main-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button class="mobile-menu-btn me-3" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
                <div>
                    <h2 class="h4 mb-1">Historique des Besoins</h2>
                    <p class="text-muted mb-0 small">Consultation des décisions passées (Toutes procédures)</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2 text-primary"></i><span class="fw-bold"><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item text-danger fw-bold" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="dashboard-card mb-4">
                <div class="card-header bg-white pt-3 border-0">
                    <h5 class="card-title fw-bold text-dark mb-0"><i class="bi bi-funnel text-primary me-2"></i>Filtres rapides</h5>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="filter-badge badge <?= $filtre_statut === 'tous' ? 'bg-primary active' : 'bg-light text-dark border' ?> px-3 py-2 fs-6" 
                                  onclick="window.location.href='?statut=tous&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Tous (<?= $stats_filtres['tous'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Approuvés' ? 'bg-success active' : 'bg-light text-dark border' ?> px-3 py-2 fs-6" 
                                  onclick="window.location.href='?statut=Approuvés&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Approuvés par Finance (<?= $stats_filtres['Approuvés'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Rejeté' ? 'bg-danger active' : 'bg-light text-dark border' ?> px-3 py-2 fs-6" 
                                  onclick="window.location.href='?statut=Rejeté&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Rejetés (<?= $stats_filtres['Rejeté'] ?>)
                            </span>
                            <span class="filter-badge badge <?= $filtre_statut === 'Correction' ? 'bg-warning active text-dark' : 'bg-light text-dark border' ?> px-3 py-2 fs-6" 
                                  onclick="window.location.href='?statut=Correction&mois=<?= $filtre_mois ?>&annee=<?= $filtre_annee ?>'">
                                Corrections (<?= $stats_filtres['Correction'] ?>)
                            </span>
                        </div>
                    </div>
                    
                    <form method="GET" class="row g-3 bg-light p-3 rounded border">
                        <input type="hidden" name="statut" value="<?= htmlspecialchars($filtre_statut) ?>">
                        <div class="col-md-4">
                            <label for="mois" class="form-label small fw-bold text-muted text-uppercase">Mois</label>
                            <select class="form-select border-primary" id="mois" name="mois">
                                <option value="">Tous les mois</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $filtre_mois == $i ? 'selected' : '' ?>>
                                        <?= $mois_fr[$i] ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="annee" class="form-label small fw-bold text-muted text-uppercase">Année</label>
                            <select class="form-select border-primary" id="annee" name="annee">
                                <?php foreach($annees as $annee): ?>
                                    <option value="<?= $annee ?>" <?= $filtre_annee == $annee ? 'selected' : '' ?>><?= $annee ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary fw-bold me-2 w-50"><i class="bi bi-filter"></i> Filtrer</button>
                            <a href="finance_historique.php" class="btn btn-outline-secondary w-50"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dashboard-card border-0">
                <div class="card-header bg-white pt-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title fw-bold text-dark mb-0">
                        <i class="bi bi-list-task text-primary me-2"></i>Dossiers Traités
                        <span class="badge bg-secondary ms-2"><?= $total_resultats ?> résultat(s)</span>
                    </h5>
                    
                    <?php if($total_resultats > 0): ?>
                        <small class="text-muted">
                            Affichage <?= $offset + 1 ?> - <?= min($offset + $limite_par_page, $total_resultats) ?> sur <?= $total_resultats ?>
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="card-body p-0">
                    <?php if (empty($besoins_traites)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">Aucune demande trouvée</h4>
                            <p class="text-muted">Aucune demande ne correspond à vos critères de filtrage.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr>
                                        <th class="ps-4">ID Besoin</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Titre / Demandeur</th>
                                        <th>Montant Estimé</th>
                                        <th>Statut Actuel</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($besoins_traites as $besoin): ?>
                                        <tr>
                                            <td class="ps-4"><code class="text-dark bg-light px-2 py-1 border rounded"><?= htmlspecialchars($besoin['id']) ?></code></td>
                                            <td><?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></td>
                                            <td><?= ($besoin['type_demande'] == 'Achat_Direct') ? '<span class="badge bg-light text-primary border border-primary">Fourniture</span>' : '<span class="badge bg-light text-secondary border">TDR / CDC</span>' ?></td>
                                            <td><strong class="text-dark"><?= htmlspecialchars($besoin['titre']) ?></strong><br><small class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($besoin['nom_demandeur'] ?? 'N/A') ?></small></td>
                                            <td class="fw-bold text-success"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</td>
                                            <td>
                                                <?php
                                                $s = $besoin['statut'];
                                                $badge_class = 'bg-secondary';
                                                
                                                if (in_array($s, ['En attente de la logistique', 'Validé', 'En cours de proforma', 'Marché attribué', 'Facturé', 'Paiement Approuvé'])) {
                                                    $badge_class = 'bg-success';
                                                } elseif (strpos($s, 'Rejet') !== false) {
                                                    $badge_class = 'bg-danger';
                                                } elseif (strpos($s, 'Correction') !== false) {
                                                    $badge_class = 'bg-warning text-dark border border-warning';
                                                }
                                                ?>
                                                <span class="badge <?= $badge_class ?>">
                                                    <?= htmlspecialchars($s) ?>
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <a href="finance_view_besoin.php?id=<?= htmlspecialchars($besoin['id']) ?>" class="btn btn-sm btn-outline-dark shadow-sm">
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

                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-0 py-4">
                    <nav aria-label="Pagination historique">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?= ($page_actuelle <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= getUrlFiltres($page_actuelle - 1) ?>">Précédent</a>
                            </li>

                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= ($page_actuelle == $i) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= getUrlFiltres($i) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= ($page_actuelle >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= getUrlFiltres($page_actuelle + 1) ?>">Suivant</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileMenuBtn').addEventListener('click', function() { document.getElementById('sidebar').classList.toggle('mobile-open'); });
        document.getElementById('mois').addEventListener('change', function() { this.form.submit(); });
        document.getElementById('annee').addEventListener('change', function() { this.form.submit(); });
    </script>
</body>
</html>