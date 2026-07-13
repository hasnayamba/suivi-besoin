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


// --- NOUVEAU : Récupérer les paramètres de filtre et de recherche depuis l'URL ---
$filter_status = $_GET['statut'] ?? '';
$search_query = $_GET['recherche'] ?? '';


// --- FONCTION get_historique_besoins MODIFIÉE ---
function get_historique_besoins($pdo, $filter_status = '', $search_query = '') {
    try {
        $sql = "SELECT b.id, b.titre, b.statut, b.date_soumission, b.fichier, u.nom AS nom_demandeur
                FROM besoins b
                LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id";

        // Condition de base : Le logisticien ne voit que ce qui est validé par la finance
        $conditions = ["b.statut NOT IN ('En attente de Finance', 'Rejeté par Finance')"];
        $params = [];

        // Ajouter la condition de filtre par statut
        if (!empty($filter_status)) {
            $conditions[] = "b.statut = ?";
            $params[] = $filter_status;
        }

        // Ajouter la condition de recherche par titre
        if (!empty($search_query)) {
            $conditions[] = "b.titre LIKE ?";
            $params[] = '%' . $search_query . '%';
        }

        $sql .= " WHERE " . implode(" AND ", $conditions);
        $sql .= " ORDER BY b.date_soumission DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur de chargement de l'historique.";
        return [];
    }
}


function get_status_badge($statut) {
    $map = [
        'En attente de validation' => 'bg-warning text-dark',
        'Correction Requise' => 'bg-warning text-dark',
        'En cours de proforma' => 'bg-info text-dark',
        'Marché attribué' => 'bg-primary',
        'Facturé' => 'bg-secondary',
        'Paiement Approuvé' => 'bg-success fw-bold',
        'Validé' => 'bg-success',
        'Rejeté' => 'bg-danger',
        'Rejeté par Comptable' => 'bg-danger fw-bold',
    ];
    $class = $map[$statut] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}

// On passe les paramètres de recherche à la fonction
$historique_besoins = get_historique_besoins($pdo, $filter_status, $search_query);

// La liste des statuts pour le filtre est aussi filtrée
$all_status = $pdo->query("SELECT DISTINCT statut FROM besoins 
                           WHERE statut NOT IN ('En attente de Finance', 'Rejeté par Finance') 
                           AND statut IS NOT NULL 
                           ORDER BY statut")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Besoins Reçus - Logisticien</title>
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
                    <h2 class="mb-1">Besoins Reçus</h2>
                    <p class="text-muted mb-0 small">Liste de tous les besoins validés par la finance</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Notifications -->
                    <div class="dropdown">
                        <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;" aria-labelledby="notifDropdown">
                            <li class="dropdown-header">Notifications</li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-2 text-muted small">Aucune notification</li>
                            <?php else: foreach ($notifications as $notif): ?>
                                <li class="px-3 py-2 <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                    <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark">
                                        <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                        <div class="small text-muted fst-italic"><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?></div>
                                    </a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    
                    <!-- Menu utilisateur -->
                    <div class="dropdown">
                        <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-2"></i><span><?= htmlspecialchars($utilisateur_nom) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="px-3 py-2">
                                <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="deconnexion.php">Déconnexion</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Barre de filtre et de recherche -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="besoins_logisticien.php" class="row g-3 align-items-center">
                        <div class="col-md-6">
                            <input type="text" name="recherche" class="form-control" placeholder="Rechercher par titre..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="statut" class="form-select">
                                <option value="">-- Filtrer par statut --</option>
                                <?php foreach ($all_status as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= ($filter_status === $status) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-primary">Filtrer</button>
                            <a href="besoins_logisticien.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Historique des besoins</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID Besoin</th>
                                    <th>Titre</th>
                                    <th>Demandeur</th>
                                    <th>Date Soumission</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($historique_besoins)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Aucun besoin ne correspond à vos critères.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historique_besoins as $besoin): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($besoin['id']) ?></code></td>
                                        <td><?= htmlspecialchars($besoin['titre']) ?></td>
                                        <td><?= htmlspecialchars($besoin['nom_demandeur'] ?? 'N/A') ?></td>
                                        <td><?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></td>
                                        <td><?= get_status_badge($besoin['statut']) ?></td>
                                        <td class="text-end">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="view_besoin.php?id=<?= htmlspecialchars($besoin['id']) ?>"><i class="bi bi-eye me-2"></i>Voir & Traiter</a></li>
                                                    <?php if (!empty($besoin['fichier'])): ?>
                                                    <li><a class="dropdown-item" href="./uploads/<?= rawurlencode($besoin['fichier']) ?>" download><i class="bi bi-download me-2"></i>Télécharger PJ</a></li>
                                                    <?php endif; ?>
                                                </ul>
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
    setInterval(checkForUpdates, 10000); // Vérifie toutes les 10 secondes
});
</script>
</body>
</html>