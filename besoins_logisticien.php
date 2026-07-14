<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au logisticien ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
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

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Logisticien';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- GESTION DES FILTRES ---
$filter_status = $_GET['statut'] ?? '';
$search_query = $_GET['recherche'] ?? '';

// --- CONFIGURATION DE LA PAGINATION ---
$limite_par_page = 10; 
$page_actuelle = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page_actuelle < 1) $page_actuelle = 1;
$offset = ($page_actuelle - 1) * $limite_par_page;

// --- CONSTRUCTION DE LA REQUÊTE ---
// Le logisticien ne voit QUE ce qui a passé la Finance
$conditions_sql = "b.statut NOT IN ('En attente de Finance', 'Rejeté par Finance')";
$params = [];

if (!empty($filter_status)) {
    $conditions_sql .= " AND b.statut = ?";
    $params[] = $filter_status;
}

if (!empty($search_query)) {
    $conditions_sql .= " AND b.titre LIKE ?";
    $params[] = '%' . $search_query . '%';
}

// 1. Compter le total pour la pagination
$sql_count = "SELECT COUNT(*) FROM besoins b LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id WHERE " . $conditions_sql;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_resultats = $stmt_count->fetchColumn();
$total_pages = ceil($total_resultats / $limite_par_page);

// 2. Récupérer les données avec tri intelligent 
$sql = "SELECT b.id, b.titre, b.statut, b.date_soumission, b.fichier, b.type_demande, u.nom AS nom_demandeur
        FROM besoins b
        LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id
        WHERE " . $conditions_sql . "
        ORDER BY FIELD(b.statut, 
            'En attente de la logistique', 
            'En attente de validation',
            'Rejeté par Comptable',
            'Validé', 
            'En cours de proforma', 
            'Appel d\'offres lancé',
            'Correction Requise',
            'Marché attribué', 
            'Facturé', 
            'Paiement Approuvé', 
            'Rejeté'
        ), b.date_soumission DESC
        LIMIT " . (int)$limite_par_page . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historique_besoins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Liste des statuts pour le select
$all_status = $pdo->query("SELECT DISTINCT statut FROM besoins 
                           WHERE statut NOT IN ('En attente de Finance', 'Rejeté par Finance', '') 
                           AND statut IS NOT NULL 
                           ORDER BY statut")->fetchAll(PDO::FETCH_COLUMN);

// Fonction pour conserver les filtres dans l'URL de la pagination
function getUrlFiltres($page) {
    $query = $_GET;
    $query['page'] = $page;
    return '?' . http_build_query($query);
}

// --- BADGES VISUELS ---
function get_status_badge($statut) {
    $badges = [
        'En attente de la logistique' => '<span class="badge bg-warning text-dark border border-warning shadow-sm"><i class="bi bi-hourglass-split me-1"></i> À Traiter</span>',
        'En attente de validation' => '<span class="badge bg-warning text-dark border border-warning shadow-sm"><i class="bi bi-hourglass-split me-1"></i> À Traiter</span>',
        'Validé' => '<span class="badge bg-info text-dark"><i class="bi bi-gear-fill me-1"></i> Validé (À Lancer)</span>',
        'En cours de proforma' => '<span class="badge bg-primary"><i class="bi bi-envelope-paper me-1"></i> Proforma en cours</span>',
        'Appel d\'offres lancé' => '<span class="badge bg-dark"><i class="bi bi-megaphone me-1"></i> AO Lancé</span>',
        'Marché attribué' => '<span class="badge bg-success"><i class="bi bi-award me-1"></i> Marché attribué</span>',
        'Facturé' => '<span class="badge bg-secondary"><i class="bi bi-send-check me-1"></i> Transmis Compta</span>',
        'Paiement Approuvé' => '<span class="badge bg-dark text-white"><i class="bi bi-check-all me-1"></i> Dossier Payé & Clos</span>',
        'Correction Requise' => '<span class="badge bg-danger"><i class="bi bi-pencil-square me-1"></i> Correction Demandeur</span>',
        'Rejeté par Comptable' => '<span class="badge bg-danger text-white border border-dark shadow-sm"><i class="bi bi-exclamation-triangle-fill me-1"></i> Rejeté Compta</span>',
        'Rejeté' => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Annulé/Rejeté</span>'
    ];
    return $badges[$statut] ?? '<span class="badge bg-secondary">' . htmlspecialchars($statut, ENT_QUOTES) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Besoins Reçus - Logistique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; // Inclusion de votre sidebar ?>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 text-primary fw-bold"><i class="bi bi-truck me-2"></i>Besoins Approuvés par la Finance</h2>
                    <p class="text-muted mb-0 small">Définissez la procédure d'achat et suivez vos dossiers.</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <div class="dropdown">
                        <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow" style="width: 320px;" aria-labelledby="notifDropdown">
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
                        <ul class="dropdown-menu dropdown-menu-end shadow">
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

        <main class="flex-fill overflow-auto p-4">

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="card mb-4 shadow-sm border-0">
                <div class="card-body bg-white rounded">
                    <form method="GET" action="besoins_logisticien.php" class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" name="recherche" class="form-control border-start-0" placeholder="Rechercher par titre de besoin..." value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select name="statut" class="form-select border-primary">
                                <option value="">Tous les statuts</option>
                                <?php foreach ($all_status as $status): ?>
                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES) ?>" <?= ($filter_status === $status) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($status, ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="bi bi-filter"></i> Filtrer</button>
                            <?php if(!empty($search_query) || !empty($filter_status)): ?>
                                <a href="besoins_logisticien.php" class="btn btn-outline-secondary" title="Réinitialiser"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white pb-0 border-0 pt-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-task text-primary me-2"></i>Tableau de Suivi Logistique</h5>
                    <?php if($total_resultats > 0): ?>
                        <small class="text-muted">Affichage <?= $offset + 1 ?> - <?= min($offset + $limite_par_page, $total_resultats) ?> sur <?= $total_resultats ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">ID Besoin</th>
                                    <th>Titre du Besoin</th>
                                    <th>Option</th>
                                    <th>Demandeur</th>
                                    <th>Date Réception</th>
                                    <th>État d'avancement</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($historique_besoins)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-folder2-open fs-1 d-block mb-3"></i>Aucun besoin ne correspond à vos critères.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historique_besoins as $besoin): 
                                    // Surlignage intelligent pour les dossiers à valider urgemment
                                    $row_class = (in_array($besoin['statut'], ['En attente de la logistique', 'En attente de validation'])) ? 'table-warning border-start border-warning border-4' : '';
                                ?>
                                    <tr class="<?= $row_class ?>">
                                        <td class="ps-3"><code class="text-dark bg-light px-2 py-1 rounded border"><?= htmlspecialchars($besoin['id']) ?></code></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($besoin['titre']) ?></td>
                                        <td>
                                            <?= ($besoin['type_demande'] == 'Achat_Direct') ? '<span class="badge bg-light text-primary border border-primary">Fourniture</span>' : '<span class="badge bg-light text-secondary border">TDR / CDC</span>' ?>
                                        </td>
                                        <td><i class="bi bi-person me-1 text-muted"></i><?= htmlspecialchars($besoin['nom_demandeur'] ?? 'N/A') ?></td>
                                        <td><?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></td>
                                        <td><?= get_status_badge($besoin['statut']) ?></td>
                                        <td class="text-end pe-3">
                                            
                                            <?php if (in_array($besoin['statut'], ['En attente de la logistique', 'En attente de validation'])): ?>
                                                <a href="view_besoin.php?id=<?= urlencode($besoin['id']) ?>" class="btn btn-sm btn-warning fw-bold text-dark shadow-sm">
                                                    <i class="bi bi-lightning-charge-fill me-1"></i>Traiter
                                                </a>
                                            <?php else: ?>
                                                <a href="view_besoin.php?id=<?= urlencode($besoin['id']) ?>" class="btn btn-sm btn-outline-dark me-1 shadow-sm" title="Voir le dossier">
                                                    <i class="bi bi-eye"></i> Voir
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!empty($besoin['fichier'])): ?>
                                                <a href="./uploads/<?= rawurlencode($besoin['fichier']) ?>" download class="btn btn-sm btn-outline-primary shadow-sm" title="Télécharger le fichier joint">
                                                    <i class="bi bi-paperclip"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-0 py-3">
                    <nav>
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
                        document.querySelectorAll('.dropdown-menu .bg-light').forEach(item => {
                            item.classList.remove('bg-light', 'fw-bold');
                            item.classList.add('text-muted');
                        });
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        });
    }

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
            .catch(error => console.error('Erreur de vérification:', error));
    }
    setInterval(checkForUpdates, 10000); // Vérification toutes les 10 secondes
});
</script>

</body>
</html>