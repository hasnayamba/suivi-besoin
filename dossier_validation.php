<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au comptable ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') {
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

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- GESTION DE L'ACTION (VALIDER/REJETER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marche_id'])) {
    $marche_id = $_POST['marche_id'];
    $besoin_id_associe = $_POST['besoin_id'];

    // Récupérer l'ID du demandeur avant toute action
    $id_demandeur = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = " . $pdo->quote($besoin_id_associe))->fetchColumn();
    $titre_besoin = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id_associe))->fetchColumn();

    // Action d'approbation
    if (isset($_POST['approuver'])) {
        $pdo->beginTransaction();
        try {
            $stmt_marche = $pdo->prepare("UPDATE marches SET statut = 'Paiement Approuvé' WHERE id = ?");
            $stmt_marche->execute([$marche_id]);

            $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Paiement Approuvé' WHERE id = ?");
            $stmt_besoin->execute([$besoin_id_associe]);

            // Notifier le demandeur
            if ($id_demandeur) {
                $message = "Votre dossier '" . htmlspecialchars($titre_besoin) . "' a été approuvé pour paiement.";
                $lien = "chef_projet.php";
                $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                $stmt_notif->execute([$id_demandeur, $message, $lien]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Le marché a été approuvé pour paiement.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur SQL lors de l'approbation : " . $e->getMessage();
        }

    // Action de rejet
    } elseif (isset($_POST['rejeter'])) {
        if (!empty($_POST['motif_rejet'])) {
            $motif = trim($_POST['motif_rejet']);
            $pdo->beginTransaction();
            try {
                $stmt_marche = $pdo->prepare("UPDATE marches SET statut = 'Rejeté par Comptable', motif_rejet = ? WHERE id = ?");
                $stmt_marche->execute([$motif, $marche_id]);

                $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Rejeté par Comptable' WHERE id = ?");
                $stmt_besoin->execute([$besoin_id_associe]);
                
                // Notifier le logisticien pour correction
                $logisticiens = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'logisticien'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($logisticiens as $logisticien_id) {
                    $message = "Dossier marché " . htmlspecialchars($marche_id) . " rejeté. Motif: " . $motif;
                    $lien = "gerer_marche.php?id=$marche_id"; 
                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                    $stmt_notif->execute([$logisticien_id, $message, $lien]);
                }
                
                // Notifier le demandeur
                if ($id_demandeur) {
                    $message_demandeur = "Dossier '" . htmlspecialchars($titre_besoin) . "' rejeté par la comptabilité. Contactez la logistique.";
                    $lien_demandeur = "chef_projet.php";
                    $stmt_notif_demandeur = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                    $stmt_notif_demandeur->execute([$id_demandeur, $message_demandeur, $lien_demandeur]);
                }

                $pdo->commit();
                $_SESSION['error'] = "Le marché a été rejeté avec le motif spécifié.";

            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Le motif de rejet est obligatoire.";
            header('Location: dossier_validation.php?besoin_id=' . $besoin_id_associe);
            exit();
        }
    }
    header('Location: comptable_dashboard.php');
    exit();
}


// --- RÉCUPÉRATION DE TOUTES LES DONNÉES DU DOSSIER ---
$besoin_id = $_GET['besoin_id'] ?? null;
if (!$besoin_id) {
    header('Location: comptable_dashboard.php');
    exit();
}

try {
    // 1. Le Besoin initial
    $stmt_besoin = $pdo->prepare("SELECT b.*, u.nom as demandeur FROM besoins b LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id WHERE b.id = ?");
    $stmt_besoin->execute([$besoin_id]);
    $besoin = $stmt_besoin->fetch();

    if (!$besoin) {
        $_SESSION['error'] = "Dossier introuvable.";
        header('Location: comptable_dashboard.php');
        exit();
    }

    // 2. Le Marché associé
    $stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE besoin_id = ?");
    $stmt_marche->execute([$besoin_id]);
    $marche = $stmt_marche->fetch();
    $marche_id = $marche['id'] ?? null;
    $type_procedure = $marche['type_procedure'] ?? 'Standard'; // On récupère le type

    // 3. La Demande de proforma
    $stmt_dp = $pdo->prepare("SELECT * FROM demandes_proforma WHERE besoin_id = ?");
    $stmt_dp->execute([$besoin_id]);
    $demande_proforma = $stmt_dp->fetch();

    // 4. Les Proformas reçues
    $proformas_recues = [];
    if ($demande_proforma) {
        $stmt_pr = $pdo->prepare("SELECT * FROM proformas_recus WHERE demande_proforma_id = ? ORDER BY statut");
        $stmt_pr->execute([$demande_proforma['id']]);
        $proformas_recues = $stmt_pr->fetchAll();
    }

    // 5. Les documents finaux
    $documents = [];
    if ($marche_id) {
        $stmt_docs = $pdo->prepare("SELECT * FROM documents_commande WHERE marche_id = ?");
        $stmt_docs->execute([$marche_id]);
        $documents = $stmt_docs->fetchAll();
    }

} catch (PDOException $e) {
    die("Erreur de chargement du dossier : " . $e->getMessage());
}

// Logique pour l'affichage des documents
$docs_a_afficher = [
    'PV' => ['label' => 'PV de Sélection', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Proforma' => ['label' => 'Proforma (Validée)', 'requis' => false, 'fichier' => null],
    'Bon de Commande' => ['label' => 'Bon de Commande', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Bon de Livraison' => ['label' => 'Bon de Livraison', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Facture' => ['label' => 'Facture', 'requis' => true, 'fichier' => null]
];

// On peuple la liste des documents avec les fichiers trouvés
foreach ($documents as $doc) {
    if (array_key_exists($doc['type_document'], $docs_a_afficher)) {
        $docs_a_afficher[$doc['type_document']]['fichier'] = $doc['fichier_joint'];
    }
}
// Pour l'achat direct, on ne marque pas le PV comme requis
if ($type_procedure === 'Achat Direct') {
    $docs_a_afficher['PV']['requis'] = false;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation du Dossier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <nav class="sidebar bg-white border-end" style="width: 260px;">
             <div class="p-4 border-bottom"><h5 class="mb-1">Comptabilité</h5></div>
            <div class="p-3">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item mb-1"><a class="nav-link" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                    <li class="nav-item mb-1"><a class="nav-link" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                    <li class="nav-item mb-1"><a class="nav-link" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
                </ul>
            </div>
        </nav>

        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Dossier de Validation</h2>
                        <p class="text-muted mb-0 small">Besoin ID: <code><?= htmlspecialchars($besoin['id']) ?></code></p>
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
                 <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <!-- Alerte d'information sur le type de procédure -->
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-0">Information sur le Dossier</h5>
                        <p class="mb-0">
                            Ce dossier a été traité via la procédure "<strong><?= htmlspecialchars($type_procedure) ?></strong>".
                            <?php if ($type_procedure === 'Achat Direct'): ?>
                                Seule la facture est obligatoire pour la validation.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="row">
                    <!-- Colonne de gauche : Infos générales -->
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="mb-0">1. Besoin Initial</h5></div>
                            <div class="card-body">
                                <p><strong>Titre:</strong> <?= htmlspecialchars($besoin['titre']) ?></p>
                                <p><strong>Demandeur:</strong> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?></p>
                                <p><strong>Date:</strong> <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></p>
                                <?php if ($besoin['fichier']): ?>
                                    <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" class="btn btn-sm btn-outline-primary" download><i class="bi bi-paperclip"></i> TDR / Spécifications</a>
                                <?php endif; ?>
                            </div>
                        </div>

                         <div class="card mb-4">
                            <div class="card-header"><h5 class="mb-0">3. Marché Attribué</h5></div>
                            <div class="card-body">
                                <p><strong>Fournisseur:</strong> <?= htmlspecialchars($marche['fournisseur'] ?? 'N/A') ?></p>
                                <p><strong>Montant Final:</strong> <span class="fw-bold text-success"><?= number_format($marche['montant'] ?? 0, 0, ',', ' ') . ' cfa' ?></span></p>
                                <p><strong>Date d'attribution:</strong> <?= $marche ? date('d/m/Y', strtotime($marche['date_debut'])) : 'N/A' ?></p>
                                <p><strong>Statut Actuel:</strong> <span class="badge bg-dark"><?= htmlspecialchars($marche['statut'] ?? 'N/A') ?></span></p>
                            </div>
                        </div>
                    </div>

                    <!-- Colonne de droite : Documents -->
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="mb-0">2. Proformas Reçues</h5></div>
                            <ul class="list-group list-group-flush">
                                <?php if (empty($proformas_recues)): ?>
                                    <li class="list-group-item text-muted">Aucune proforma enregistrée.</li>
                                <?php else: foreach ($proformas_recues as $proforma): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center <?= $proforma['statut'] == 'Validé' ? 'list-group-item-success' : '' ?>">
                                        <div>
                                            <?= htmlspecialchars($proforma['fournisseur']) ?>
                                            - <span class="fw-bold"><?= number_format($proforma['montant'], 0, ',', ' ') ?> cfa</span>
                                        </div>
                                        <?php if ($proforma['statut'] == 'Validé'): ?><span class="badge bg-success">Offre Choisie</span><?php endif; ?>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="mb-0">4. Documents Justificatifs</h5></div>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($docs_a_afficher as $doc_info): ?>
                                    <?php 
                                    // On n'affiche pas les documents optionnels qui n'ont pas été fournis
                                    if (!$doc_info['requis'] && empty($doc_info['fichier'])) {
                                        continue;
                                    }
                                    ?>
                                     <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <strong>
                                            <?= htmlspecialchars($doc_info['label']) ?>
                                            <?php if (!$doc_info['requis']): ?>
                                                <span class="badge bg-secondary">Optionnel</span>
                                            <?php endif; ?>
                                        </strong>
                                        
                                        <?php if (!empty($doc_info['fichier'])): ?>
                                            <a href="uploads/<?= rawurlencode($doc_info['fichier']) ?>" class="btn btn-sm btn-outline-secondary" download><i class="bi bi-download"></i> Télécharger</a>
                                        <?php else: ?>
                                            <span class="text-danger fst-italic"><i class="bi bi-x-circle me-1"></i> Manquant</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                         <!-- Section d'actions pour le comptable -->
                        <?php if ($marche && $marche['statut'] === 'Facturé'): ?>
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white"><h5 class="mb-0">Action Requise</h5></div>
                            <div class="card-body text-center">
                                <p>Veuillez vérifier tous les documents avant de valider le dossier pour paiement.</p>
                                <button type="button" class="btn btn-danger btn-lg me-2" data-bs-toggle="modal" data-bs-target="#rejetModal"><i class="bi bi-x-circle me-2"></i>Rejeter le Dossier</button>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                    <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                                    <button type="submit" name="approuver" class="btn btn-success btn-lg" onclick="return confirm('Confirmez-vous l\'approbation pour paiement ?')"><i class="bi bi-check-circle me-2"></i>Approuver pour Paiement</button>
                                </form>
                            </div>
                        </div>
                        <?php elseif($marche && $marche['statut'] === 'Rejeté par Comptable'): ?>
                        <div class="alert alert-danger">
                            <h5 class="alert-heading">Dossier Rejeté</h5>
                            <p><strong>Motif :</strong> <?= htmlspecialchars($marche['motif_rejet']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modale pour le motif de rejet -->
    <div class="modal fade" id="rejetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Motif du Rejet</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>"> 
                        <div class="mb-3">
                            <label for="motif_rejet" class="form-label">Veuillez spécifier la raison du rejet :</label>
                            <textarea class="form-control" id="motif_rejet" name="motif_rejet" rows="4" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="rejeter" class="btn btn-danger">Confirmer le Rejet</button>
                    </div>
                </form>
            </div>
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
                        });
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
                    });
            }
            setInterval(checkForUpdates, 10000); // Vérifie toutes les 10 secondes
        });
    </script>
</body>
</html>