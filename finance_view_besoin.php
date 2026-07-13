<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au rôle 'finance' ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'finance') {
    header('Location: login.php');
    exit();
}
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com'; // Ajout pour cohérence

// --- RÉCUPÉRATION DE L'ID ET DES DONNÉES ---
// On récupère l'ID depuis GET (affichage) ou POST (traitement du formulaire)
$besoin_id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$besoin_id) {
    header('Location: finance_dashboard.php');
    exit();
}

// --- RÉCUPÉRATION DES DONNÉES DU BESOIN ---
try {
    $stmt_info = $pdo->prepare("SELECT b.*, u.nom as demandeur FROM besoins b LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id WHERE b.id = ?");
    $stmt_info->execute([$besoin_id]);
    $besoin = $stmt_info->fetch();
} catch (PDOException $e) {
    die("Erreur de chargement du besoin : " . $e->getMessage());
}

if (!$besoin) {
    $_SESSION['error'] = "Ce besoin est introuvable.";
    header('Location: finance_dashboard.php');
    exit();
}


// --- GESTION DES ACTIONS (VALIDER / REJETER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = '';
    $motif = null;
    $message_notif_demandeur = '';
    
    $pdo->beginTransaction();
    try {
        if (isset($_POST['approuver'])) {
            // --- Action: Approuver ---
            $new_status = 'En attente de validation'; // Statut suivant (pour le logisticien)
            $message_notif_demandeur = "Votre besoin '" . htmlspecialchars($besoin['titre']) . "' a été validé par la Finance.";
            $_SESSION['success'] = "Le besoin a été validé et transmis à la logistique.";

            // Notifier tous les logisticiens
            $logisticiens = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'logisticien'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($logisticiens as $logisticien_id) {
                $message_notif_logisticien = "Nouveau besoin '" . htmlspecialchars($besoin['titre']) . "' validé par la Finance, en attente de traitement.";
                $lien = "view_besoin.php?id=$besoin_id";
                $stmt_notif_log = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                $stmt_notif_log->execute([$logisticien_id, $message_notif_logisticien, $lien]);
            }

        } elseif (isset($_POST['rejeter'])) {
            // --- Action: Rejeter ---
            if (!empty($_POST['motif_rejet'])) {
                $new_status = 'Rejeté par Finance';
                $motif = trim($_POST['motif_rejet']);
                $message_notif_demandeur = "Votre besoin '" . htmlspecialchars($besoin['titre']) . "' a été rejeté par la Finance.";
                $_SESSION['error'] = "Le besoin a été rejeté.";
            } else {
                $_SESSION['error'] = "Le motif de rejet est obligatoire.";
                header('Location: finance_view_besoin.php?id=' . $besoin_id);
                $pdo->rollBack(); // Annuler la transaction s'il y en avait une
                exit();
            }
        }

        if ($new_status) {
            // 1. Mettre à jour le statut et le motif du besoin
            $stmt_update = $pdo->prepare("UPDATE besoins SET statut = ?, motif_rejet = ? WHERE id = ?");
            $stmt_update->execute([$new_status, $motif, $besoin_id]);

            // 2. Envoyer la notification au demandeur initial
            $id_demandeur = $besoin['utilisateur_id'];
            if ($id_demandeur) {
                $lien = "chef_projet.php";
                $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                $stmt_notif->execute([$id_demandeur, $message_notif_demandeur, $lien]);
            }
            
            $pdo->commit();
        } else {
            $pdo->rollBack(); // Rollback si aucune action n'a été définie
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur de mise à jour : " . $e->getMessage();
    }
    
    // Rediriger vers le tableau de bord principal
    header('Location: finance_dashboard.php');
    exit();
}

// Fonction pour les badges de statut
function get_status_badge($statut) {
    $map = [
        'En attente de Finance' => 'bg-warning text-dark',
        'Rejeté par Finance' => 'bg-danger',
        'En attente de validation' => 'bg-info text-dark',
        'Validé' => 'bg-success',
        'Correction Requise' => 'bg-warning text-dark',
        'En cours de proforma' => 'bg-info text-dark',
        'Marché attribué' => 'bg-primary',
        'Facturé' => 'bg-secondary',
        'Paiement Approuvé' => 'bg-success fw-bold',
        'Rejeté' => 'bg-danger',
        'Rejeté par Comptable' => 'bg-danger fw-bold',
    ];
    $class = $map[$statut] ?? 'bg-secondary';
    return '<span class="badge fs-6 ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail du Besoin - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom"><h5 class="mb-1">Service Finance</h5></div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link active" href="finance_dashboard.php"><i class="bi bi-wallet2 me-2"></i>Besoins à valider</a></li>
            </ul>
        </div>
    </nav>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Validation Financière</h2>
                    <p class="text-muted mb-0 small">ID: <code><?= htmlspecialchars($besoin['id']) ?></code></p>
                </div>
                <a href="finance_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour à la liste</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?= htmlspecialchars($besoin['titre']) ?></h4>
                    <?= get_status_badge($besoin['statut']) ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5>Description complète</h5>
                            <p style="white-space: pre-wrap;"><?= htmlspecialchars($besoin['description'] ?? '') ?></p>
                        </div>
                        <div class="col-md-4 border-start">
                            <h5>Informations</h5>
                            <ul class="list-unstyled">
                                <li><strong>Demandeur:</strong> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?></li>
                                <li><strong>Date de soumission:</strong> <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></li>
                                
                                <?php if (!empty($besoin['montant'])): ?>
                                <li class="mt-2">
                                    <strong>Montant Estimatif:</strong><br>
                                    <span class="fs-4 fw-bold text-success">
                                        <?= number_format($besoin['montant'], 0, ',', ' ') ?> cfa
                                    </span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <?php if ($besoin['fichier']): ?>
                                <h5 class="mt-4">Pièce Jointe (TDR)</h5>
                                <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" class="btn btn-outline-primary" download>
                                    <i class="bi bi-paperclip"></i> Télécharger le document
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-center">
                    
                    <?php if ($besoin['statut'] === 'En attente de Finance'): ?>
                        <h5>Action Requise</h5>
                        <button type="button" class="btn btn-danger btn-lg me-2" data-bs-toggle="modal" data-bs-target="#rejetModal">
                            <i class="bi bi-x-circle me-1"></i> Rejeter
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir valider ce besoin ? Il sera transmis à la logistique.')">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
                            <button type="submit" name="approuver" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle me-1"></i> Valider et Transmettre
                            </button>
                        </form>
                    <?php elseif($besoin['statut'] === 'Rejeté par Finance'): ?>
                         <div class="alert alert-danger mb-0">
                            <strong>Ce besoin a été rejeté.</strong><br>
                            <strong>Motif :</strong> <?= htmlspecialchars($besoin['motif_rejet']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Ce dossier a déjà été traité et transmis à la logistique.</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modale pour le motif de REJET -->
<div class="modal fade" id="rejetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Motif du Rejet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
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
</body>
</html>