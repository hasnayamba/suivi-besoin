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

// --- RÉCUPÉRATION DES APPELS D'OFFRES ---
function get_appels_offres($pdo) {
    try {
        $sql = "SELECT ao.*, b.titre AS besoin_titre
                FROM appels_offre ao
                JOIN besoins b ON ao.besoin_id = b.id
                ORDER BY ao.date_creation DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur de chargement des appels d'offres.";
        return [];
    }
}

$appels_offres = get_appels_offres($pdo);

function get_ao_status_badge($statut) {
    $map = [
        'Lancé' => 'bg-primary',
        'Attribué' => 'bg-success',
        'Annulé' => 'bg-danger'
    ];
    $class = $map[$statut] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des Appels d'Offres</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Suivi des Appels d'Offres</h2>
                    <p class="text-muted mb-0 small">Liste de tous les appels d'offres lancés</p>
                </div>
                <!-- Header avec notifications et menu utilisateur -->
                <div class="d-flex align-items-center gap-3">
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
             <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                 <div class="card-header"><h5 class="card-title mb-0">Appels d'offres en cours et passés</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID Appel d'Offre</th>
                                    <th>Titre du Besoin</th>
                                    <th>Canal</th>
                                    <th>Date Limite</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($appels_offres)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Aucun appel d'offres lancé pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($appels_offres as $ao): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($ao['id']) ?></code></td>
                                        <td><?= htmlspecialchars($ao['besoin_titre']) ?></td>
                                        <td><?= htmlspecialchars($ao['canal_publication']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($ao['date_limite'])) ?></td>
                                        <td><?= get_ao_status_badge($ao['statut']) ?></td>
                                        <td class="text-end">
                                            <div class="btn-group" role="group">
                                                <a href="uploads/<?= rawurlencode($ao['dossier_ao']) ?>" class="btn btn-sm btn-outline-secondary" download title="Télécharger le DAO">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <a href="details_ao.php?ao_id=<?= htmlspecialchars($ao['id']) ?>" class="btn btn-sm btn-outline-info" title="Voir les détails">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="gerer_appel_offre.php?ao_id=<?= htmlspecialchars($ao['id']) ?>" class="btn btn-sm btn-primary" title="Gérer le dossier">
                                                    <i class="bi bi-pencil-square"></i> Gérer
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
    // Script pour marquer les notifications comme lues
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

    // Script pour la mise à jour en temps réel des notifications (Polling)
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