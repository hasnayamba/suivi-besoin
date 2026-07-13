<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- LOGIQUE NOTIFICATIONS (Inchangée) ---
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
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- FONCTIONS MÉTIERS MISES À JOUR ---

function get_contrat_metrics($pdo) {
    $metrics = ['contrats_en_cours' => 0, 'contrats_expires' => 0, 'montant_total_ht' => 0];
    try {
        $metrics['contrats_en_cours'] = $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'En cours'")->fetchColumn();
        $metrics['contrats_expires'] = $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'Expiré' OR (date_fin_prevue < CURDATE() AND statut = 'En cours')")->fetchColumn();
        $metrics['montant_total_ht'] = $pdo->query("SELECT SUM(montant_ht) FROM contrats WHERE statut = 'En cours'")->fetchColumn() ?? 0;
    } catch (PDOException $e) { error_log("Erreur métriques: " . $e->getMessage()); }
    return $metrics;
}

/**
 * Récupère TOUS les contrats proches de l'expiration (pas de pagination ici, car urgence).
 */
function get_contrats_bientot_expires($pdo) {
    try {
        $sql = "SELECT * FROM contrats 
                WHERE statut = 'En cours' 
                AND date_fin_prevue BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) 
                ORDER BY date_fin_prevue ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

function get_list_antennes($pdo) {
    try {
        return $pdo->query("SELECT DISTINCT antenne FROM contrats WHERE antenne IS NOT NULL AND antenne != '' ORDER BY antenne ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { return []; }
}

/**
 * Récupère TOUS les contrats filtrés. La pagination sera gérée par DataTables.
 */
function get_contrats_filtres_datatable($pdo, $antenne = null, $statut = 'all') {
    $sql = "SELECT *, (montant_max_annuel - paiement_effectue) AS solde_restant FROM contrats WHERE 1=1";
    $params = [];

    // Filtre par Antenne
    if (!empty($antenne)) { $sql .= " AND antenne = ?"; $params[] = $antenne; }
    
    // Filtre par Statut
    if ($statut !== 'all') { $sql .= " AND statut = ?"; $params[] = $statut; }

    // NOTE : La recherche globale (nom_fournisseur, objet_contrat, etc.) est gérée par DataTables.
    // Pour ne pas surcharger la requête initiale, on ne l'applique pas ici, 
    // mais on utilise le filtre Antenne/Statut pour réduire le dataset initial.
    
    $sql .= " ORDER BY date_creation DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { 
        error_log("Erreur de contrats filtres: " . $e->getMessage());
        return []; 
    }
}

// --- INITIALISATION ---
$metrics = get_contrat_metrics($pdo);
$liste_antennes = get_list_antennes($pdo);

// Récupération des filtres pour DataTables (qui agissent comme des pré-filtres)
$filtre_antenne = $_GET['antenne'] ?? '';
$filtre_statut = $_GET['statut'] ?? 'all'; 

$contrats_alerte = get_contrats_bientot_expires($pdo);
$total_alertes = count($contrats_alerte);

$contrats = get_contrats_filtres_datatable($pdo, $filtre_antenne, $filtre_statut);
$total_contrats = count($contrats); // Total après pré-filtrage Antenne/Statut
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
        /* Styles pour le défilement fixe des alertes (zone critique) */
        .alert-scroll-container {
            /* Fixe la hauteur maximale pour ne pas surcharger la page */
            max-height: 400px; 
            overflow-y: auto;  
        }
        .alert-scroll-container table {
             /* Assure que la table remplit bien le conteneur */
             width: 100% !important;
        }
        /* Fixer l'entête des alertes */
        .alert-scroll-container thead th {
            position: sticky;
            top: 0;
            z-index: 10; 
            background-color: #dc3545; /* Rouge danger */
            color: white;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'sidebar_contrat.php'; ?> 

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                  
                     <h2 class="h4 mb-1 fw-bold">Gestion des Contrats</h2>
                    <p class="text-muted mb-0 small">Gestion et suivi logistique</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;">
                            <li class="dropdown-header">Notifications</li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li class="px-3 py-2 <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark">
                                        <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                    </a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-2"></i><span><?= htmlspecialchars($utilisateur_nom) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="deconnexion.php">Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-fill overflow-auto p-4">
            
            <div class="row g-4 mb-4">
                <div class="col-md-4"><div class="card text-center p-3 h-100"><h6 class="text-muted">Contrats En Cours</h6><h3 class="mb-0"><?= $metrics['contrats_en_cours'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100"><h6 class="text-muted">Contrats Expirés</h6><h3 class="mb-0"><?= $metrics['contrats_expires'] ?></h3></div></div>
                <div class="col-md-4"><div class="card text-center p-3 h-100 bg-success text-white"><h6 class="text-white-50">Montant Total (HT)</h6><h3 class="mb-0"><?= number_format($metrics['montant_total_ht'], 0, ',', ' ') ?> cfa</h3></div></div>
            </div>
            
            <?php if ($total_alertes > 0): ?>
            <div class="card border-danger mb-4">
                <div class="card-header bg-danger text-white d-flex align-items-center justify-content-between">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span class="h5 mb-0">Attention : Expire bientôt (< 60 jours)</span>
                    </div>
                    <span class="badge bg-white text-danger"><?= $total_alertes ?> alertes</span>
                </div>
                
                <div class="card-body p-0 alert-scroll-container">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>N° Ref</th>
                                <th>Fournisseur</th>
                                <th>Objet</th>
                                <th>Date Fin</th>
                                <th>Reste</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contrats_alerte as $c): 
                                $dateFin = new DateTime($c['date_fin_prevue']);
                                $joursRestants = (new DateTime())->diff($dateFin)->days;
                            ?>
                            <tr>
                                <td class="fw-bold ps-3"><?= htmlspecialchars($c['num_contrat'] ?? $c['num_mandat']) ?></td>
                                <td class="text-danger"><?= htmlspecialchars($c['nom_fournisseur']) ?></td>
                                <td><?= htmlspecialchars($c['objet_contrat']) ?></td>
                                <td class="text-danger fw-bold"><?= date('d/m/Y', strtotime($c['date_fin_prevue'])) ?></td>
                                <td><span class="badge bg-danger"><?= $joursRestants ?> j</span></td>
                                <td class="text-end pe-3">
                                    <a href="modifier_contrat.php?id=<?= htmlspecialchars($c['id']) ?>" class="btn btn-sm btn-light text-danger border-danger">
                                        Renouveler
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-end mb-4">
                <a href="ajouter_contrat.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>Nouveau Contrat
                </a>
            </div>

            <div class="card mb-4">
                <div class="card-body py-3 bg-light border rounded">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Pré-filtrer par Antenne</label>
                            <select class="form-select" name="antenne">
                                <option value="" <?= empty($filtre_antenne) ? 'selected' : '' ?>>Toutes les Antennes</option>
                                <?php foreach ($liste_antennes as $ant): ?>
                                    <option value="<?= htmlspecialchars($ant) ?>" <?= $filtre_antenne === $ant ? 'selected' : '' ?>><?= htmlspecialchars($ant) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Pré-filtrer par Statut</label>
                            <select class="form-select" name="statut">
                                <option value="all" <?= $filtre_statut == 'all' ? 'selected' : '' ?>>Tous les Statuts</option>
                                <option value="En cours" <?= $filtre_statut == 'En cours' ? 'selected' : '' ?>>En Cours</option>
                                <option value="Expiré" <?= $filtre_statut == 'Expiré' ? 'selected' : '' ?>>Expiré</option>
                                <option value="Cloturé" <?= $filtre_statut == 'Cloturé' ? 'selected' : '' ?>>Cloturé</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-dark"><i class="bi bi-filter"></i> Appliquer pré-filtres</button>
                            <?php if ($filtre_antenne || $filtre_statut != 'all'): ?>
                                <a href="?" class="btn btn-outline-secondary ms-2">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0">Historique des Contrats</h5>
                    <p class="small text-muted mb-0">Total affiché : <?= $total_contrats ?> contrats (Utilisez la recherche ci-dessous).</p>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableContrats" class="table table-hover align-middle" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>N°</th>
                                    <th>Mandat</th>
                                    <th>Antenne</th>
                                    <th>Fournisseur</th>
                                    <th>Objet</th>
                                    <th>Montant</th>
                                    <th>Solde Restant</th> 
                                    <th>Fin prévue</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($contrats)): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">Aucun contrat trouvé pour les pré-filtres sélectionnés.</td></tr>
                            <?php else: ?>
                            <?php foreach ($contrats as $contrat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($contrat['id']) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($contrat['num_mandat'] ?? '-') ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($contrat['antenne'] ?? '-') ?></span></td>
                                    <td><?= htmlspecialchars($contrat['nom_fournisseur']) ?></td>
                                    <td><?= substr(htmlspecialchars($contrat['objet_contrat'] ?? ''), 0, 40) ?></td>
                                    <td><?= $contrat['montant_ht'] ? number_format($contrat['montant_ht'], 0, ',', ' ') . ' cfa' : '-' ?></td>
                                    <td class="<?= ($contrat['solde_restant'] ?? 0) < 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                        <?php if (isset($contrat['montant_max_annuel']) && isset($contrat['paiement_effectue'])): ?>
                                            <?= number_format($contrat['solde_restant'], 0, ',', ' ') . ' cfa' ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $contrat['date_fin_prevue'] ? date('d/m/Y', strtotime($contrat['date_fin_prevue'])) : '-' ?></td>
                                    <td>
                                        <?php if($contrat['statut'] == 'Expiré'): ?>
                                            <span class="badge bg-danger">Expiré</span>
                                        <?php elseif($contrat['statut'] == 'Cloturé'): ?>
                                            <span class="badge bg-secondary">Cloturé</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">En cours</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="detail_contrat.php?id=<?= htmlspecialchars($contrat['id']) ?>" class="btn btn-sm btn-outline-info" title="Détails"><i class="bi bi-eye"></i></a>
                                            <a href="modifier_contrat.php?id=<?= htmlspecialchars($contrat['id']) ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
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
    // Initialisation de DataTables
    $('#tableContrats').DataTable({
        "pageLength": 10, // 10 contrats par page par défaut
        "order": [[ 0, "desc" ]], // Tri par ID décroissant par défaut
        // Paramètres pour l'expérience utilisateur et les colonnes
        "columnDefs": [
            { "orderable": false, "targets": [9] } // Désactiver le tri sur la colonne 'Actions'
        ],
        "language": {
            // Traduction en français (assurez-vous que ce fichier est accessible)
            "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json" 
        }
    });

    // Logique Notifications (inchangée)
    const notifDropdown = document.getElementById('notifDropdown');
    if (notifDropdown) {
        notifDropdown.addEventListener('show.bs.dropdown', function () {
            const unreadBadge = notifDropdown.querySelector('.badge');
            if (unreadBadge) {
                // Marque les notifications comme lues
                fetch('marquer_notifications_lues.php', { method: 'POST' })
                .then(response => { if (response.ok) unreadBadge.remove(); });
            }
        });
    }
});
</script>

</body>
</html>