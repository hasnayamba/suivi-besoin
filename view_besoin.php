<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au logisticien ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- RÉCUPÉRATION DE L'ID ET DES DONNÉES ---
$besoin_id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$besoin_id) {
    header('Location: besoins_logisticien.php');
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
    header('Location: besoins_logisticien.php');
    exit();
}


// --- GESTION DES ACTIONS (VALIDER / CORRIGER / REJETER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = '';
    $motif = null;
    $message_notif = '';

    if (isset($_POST['valider'])) {
        $new_status = 'Validé';
        $message_notif = "Votre besoin '" . htmlspecialchars($besoin['titre']) . "' a été validé.";
        $_SESSION['success'] = "Le besoin a été validé avec succès.";

    } elseif (isset($_POST['rejeter_definitivement'])) {
        $new_status = 'Rejeté'; // Rejet final
        $message_notif = "Votre besoin '" . htmlspecialchars($besoin['titre']) . "' a été rejeté définitivement.";
        $_SESSION['error'] = "Le besoin a été rejeté définitivement.";

    } elseif (isset($_POST['demander_correction'])) {
        if (!empty($_POST['motif_rejet'])) {
            $new_status = 'Correction Requise';
            $motif = trim($_POST['motif_rejet']);
            $message_notif = "Une correction est requise pour votre besoin '" . htmlspecialchars($besoin['titre']) . "'.";
            $_SESSION['error'] = "Une demande de correction a été envoyée.";
        } else {
            $_SESSION['error'] = "Le motif de correction est obligatoire.";
        }
    }

    if ($new_status) {
        $pdo->beginTransaction();
        try {
            // 1. Mettre à jour le statut et le motif du besoin
            $stmt_update = $pdo->prepare("UPDATE besoins SET statut = ?, motif_rejet = ? WHERE id = ?");
            $stmt_update->execute([$new_status, $motif, $besoin_id]);

            // 2. Envoyer la notification au demandeur
            $id_demandeur = $besoin['utilisateur_id'];
            if ($id_demandeur) {
                $lien = "chef_projet.php";
                $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                $stmt_notif->execute([$id_demandeur, $message_notif, $lien]);
            }
            
            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur de mise à jour : " . $e->getMessage();
        }
    }
    header('Location: view_besoin.php?id=' . $besoin_id);
    exit();
}

// Fonction pour les badges de statut
function get_status_badge($statut) {
    $map = [
        'En attente de validation' => 'bg-warning text-dark',
        'Correction Requise' => 'bg-warning text-dark',
        'En cours de proforma' => 'bg-info text-dark',
        'Marché attribué' => 'bg-success fw-bold',
        'Validé' => 'bg-success',
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
    <meta name="viewport" content="width=device-width, initial-scale-1.0">
    <title>Détail du Besoin</title>
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
                    <h2 class="mb-1">Détail du Besoin</h2>
                    <p class="text-muted mb-0 small">ID: <code><?= htmlspecialchars($besoin['id']) ?></code></p>
                </div>
                <a href="besoins_logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour à la liste</a>
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><?= htmlspecialchars($besoin['titre']) ?></h4>
                    <?= get_status_badge($besoin['statut']) ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5>Cadre(projet)</h5>
                            <p style="white-space: pre-wrap;"><?= htmlspecialchars($besoin['description'] ?? '') ?></p>
                        </div>
                        <div class="col-md-4 border-start">
                            <h5>Informations</h5>
                            <ul class="list-unstyled">
                                <li><strong>Demandeur:</strong> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?></li>
                                <li><strong>Date de soumission:</strong> <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></li>
                            </ul>
                            <?php if ($besoin['fichier']): ?>
                                <h5 class="mt-4">Pièce Jointe</h5>
                                <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" class="btn btn-outline-primary" download>
                                    <i class="bi bi-paperclip"></i> Télécharger le document
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-center">
                    
                    <?php if ($besoin['statut'] === 'En attente de validation' || $besoin['statut'] === 'Correction Requise'): ?>
                        <h5>Action Requise</h5>
                        <button type="button" class="btn btn-warning btn-lg me-2" data-bs-toggle="modal" data-bs-target="#correctionModal">
                            <i class="bi bi-pencil-square me-1"></i> Correction Requise
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir rejeter définitivement ce besoin ? Cette action est irréversible.')">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
                            <button type="submit" name="rejeter_definitivement" class="btn btn-danger btn-lg me-2">
                                <i class="bi bi-trash-fill me-1"></i> Rejeter
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir valider ce besoin ?')">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
                            <button type="submit" name="valider" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle me-1"></i> Valider
                            </button>
                        </form>

                    <?php elseif ($besoin['statut'] === 'Validé'): ?>
                        <h5>Étape suivante : Choisir le type de passation</h5>
                        <div class="btn-group" role="group" aria-label="Actions de passation">
                            
                            <a href="achat_direct.php?besoin_id=<?= htmlspecialchars($besoin['id']) ?>" class="btn btn-info btn-lg">
                                <i class="bi bi-cart-check me-1"></i> Achat Direct
                            </a>
                            <a href="demande_proforma.php" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-seam me-1"></i> Lancer Proforma
                            </a>
                            <a href="appel_offre.php?besoin_id=<?= htmlspecialchars($besoin['id']) ?>" class="btn btn-warning btn-lg">
                                <i class="bi bi-megaphone me-1"></i> Appel d'Offre
                            </a>
                        </div>
                        <?php elseif ($besoin['statut'] === 'Rejeté'): ?>
                         <div class="alert alert-danger mb-0">
                            <strong>Ce besoin a été rejeté définitivement.</strong>
                        </div>

                    <?php else: ?>
                        <p class="text-muted mb-0">Ce besoin est en cours de traitement (Statut: <?= htmlspecialchars($besoin['statut']) ?>).</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="correctionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Demande de Correction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
                    <div class="mb-3">
                        <label for="motif_rejet" class="form-label">Veuillez spécifier les corrections requises :</label>
                        <textarea class="form-control" id="motif_rejet" name="motif_rejet" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="demander_correction" class="btn btn-warning">Envoyer la demande</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>