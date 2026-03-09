<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'finance') {
    header('Location: login.php');
    exit();
}

$id_besoin = $_GET['id'] ?? null;
if (!$id_besoin) { header('Location: finance_dashboard.php'); exit(); }

// --- TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $motif = $_POST['motif'] ?? null;
    
    // Récupérer infos pour notification
    $stmt_info = $pdo->prepare("SELECT utilisateur_id, titre FROM besoins WHERE id = ?");
    $stmt_info->execute([$id_besoin]);
    $info = $stmt_info->fetch();
    
    $id_demandeur = $info['utilisateur_id'];
    $titre_besoin = $info['titre'];

    if ($action === 'valider') {
        // 1. VALIDATION
        $pdo->prepare("UPDATE besoins SET statut = 'En attente de validation' WHERE id = ?")->execute([$id_besoin]);
        
        // Notif Logisticien(s)
        $logs = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'logisticien'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($logs as $log_id) {
            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
                ->execute([$log_id, "Budget validé pour '$titre_besoin'. À traiter.", "view_besoin.php?id=$id_besoin"]);
        }
        // Notif Demandeur
        $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
            ->execute([$id_demandeur, "Bonne nouvelle : Votre besoin '$titre_besoin' a été approuvé par la Finance.", "chef_projet.php"]);

        $_SESSION['success'] = "Budget approuvé avec succès ! Le dossier est transmis à la logistique.";

    } elseif ($action === 'corriger') {
        // 2. CORRECTION
        $pdo->prepare("UPDATE besoins SET statut = 'Correction Requise', motif_rejet = ? WHERE id = ?")->execute([$motif, $id_besoin]);
        
        $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
            ->execute([$id_demandeur, "Correction requise (Finance) sur '$titre_besoin'. Motif : $motif", "chef_projet.php"]);

        $_SESSION['warning'] = "Le dossier a été renvoyé à l'initiateur pour correction.";

    } elseif ($action === 'rejeter') {
        // 3. REJET
        $pdo->prepare("UPDATE besoins SET statut = 'Rejeté par Finance', motif_rejet = ? WHERE id = ?")->execute([$motif, $id_besoin]);
        
        $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
            ->execute([$id_demandeur, "Rejet définitif (Finance) pour '$titre_besoin'. Motif : $motif", "chef_projet.php"]);

        $_SESSION['error'] = "Le dossier a été rejeté définitivement.";
    }
    
    // IMPORTANT : On reste sur la même page pour voir le résultat
    header('Location: finance_view_besoin.php?id=' . $id_besoin);
    exit();
}

// --- CHARGEMENT DES DONNÉES (APRÈS TRAITEMENT) ---
$stmt = $pdo->prepare("SELECT b.*, u.nom as demandeur, p.nom as projet_nom FROM besoins b LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id LEFT JOIN projets p ON b.projet_id = p.id WHERE b.id = ?");
$stmt->execute([$id_besoin]);
$besoin = $stmt->fetch();

if (!$besoin) { die("Erreur : Dossier introuvable."); }

$articles = [];
if ($besoin['type_demande'] === 'Achat_Direct') {
    $articles = $pdo->query("SELECT * FROM besoin_articles WHERE besoin_id = '$id_besoin'")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrôle Budgétaire</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100 flex-column">
    <header class="bg-primary text-white p-3 shadow-sm">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="bi bi-wallet2 me-2"></i>Portail Finance</h5>
            <a href="finance_dashboard.php" class="btn btn-light btn-sm fw-bold text-primary"><i class="bi bi-arrow-left me-1"></i>Retour au tableau</a>
        </div>
    </header>

    <div class="container py-4 flex-fill overflow-auto">
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                <i class="bi bi-x-circle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm text-dark">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['warning']; unset($_SESSION['warning']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(strpos($besoin['statut'], 'Rejet') !== false): ?>
            <div class="alert alert-danger border-danger">
                <strong>Statut actuel : REJETÉ</strong><br>
                Motif : <em><?= htmlspecialchars($besoin['motif_rejet']) ?></em>
            </div>
        <?php elseif($besoin['statut'] === 'Correction Requise'): ?>
            <div class="alert alert-warning border-warning text-dark">
                <strong>Statut actuel : EN CORRECTION</strong><br>
                Le dossier est chez l'initiateur pour modification.
            </div>
        <?php elseif($besoin['statut'] !== 'En attente de Finance'): ?>
            <div class="alert alert-success border-success">
                <strong>Statut actuel : VALIDÉ</strong><br>
                Le budget a été approuvé. Le dossier suit son cours.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <small class="text-muted text-uppercase fw-bold">Réf: <?= htmlspecialchars($besoin['id']) ?></small>
                                <h3 class="fw-bold text-dark mt-1"><?= htmlspecialchars($besoin['titre']) ?></h3>
                            </div>
                            <?php 
                                $badgeClass = 'bg-secondary';
                                if($besoin['statut'] == 'En attente de Finance') $badgeClass = 'bg-warning text-dark border border-warning';
                                elseif(strpos($besoin['statut'], 'Rejet') !== false) $badgeClass = 'bg-danger';
                                else $badgeClass = 'bg-success';
                            ?>
                            <span class="badge <?= $badgeClass ?> px-3 py-2 fs-6"><?= htmlspecialchars($besoin['statut']) ?></span>
                        </div>

                        <div class="row bg-light p-3 rounded mb-4 border mx-0">
                            <div class="col-md-4"><small class="text-muted d-block fw-bold">Demandeur</small><div class="mt-1"><i class="bi bi-person-fill text-muted me-1"></i><?= htmlspecialchars($besoin['demandeur']) ?></div></div>
                            <div class="col-md-4 border-start"><small class="text-muted d-block fw-bold">Date Soumission</small><div class="mt-1"><i class="bi bi-calendar3 text-muted me-1"></i><?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></div></div>
                            <div class="col-md-4 border-start"><small class="text-muted d-block fw-bold">Montant Total</small><div class="mt-1 text-success fw-bold fs-5"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</div></div>
                        </div>

                        <div class="mb-4">
                            <span class="badge bg-secondary me-2">Source : <?= htmlspecialchars($besoin['projet_nom'] ?? 'Fonds Propres') ?></span>
                            <?php if(!empty($besoin['ligne_imputation'])): ?>
                                <span class="badge bg-light text-dark border">Imputation : <?= htmlspecialchars($besoin['ligne_imputation']) ?></span>
                            <?php endif; ?>
                        </div>

                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-primary"><i class="bi bi-list-check me-2"></i>Détails de la demande</h6>
                        
                        <?php if(!empty($articles)): ?>
                            <div class="table-responsive border rounded">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-light"><tr><th>Désignation</th><th>Unité</th><th class="text-center">Qté</th><th class="text-end">Prix Unit.</th><th class="text-end">Total</th></tr></thead>
                                    <tbody>
                                        <?php foreach($articles as $art): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($art['designation']) ?></td>
                                            <td><?= htmlspecialchars($art['unite'] ?? '-') ?></td>
                                            <td class="text-center fw-bold"><?= $art['quantite'] ?></td>
                                            <td class="text-end"><?= number_format($art['pu_indicatif'], 0, ',', ' ') ?></td>
                                            <td class="text-end fw-bold text-success"><?= number_format($art['prix_total'], 0, ',', ' ') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-3 bg-light rounded border text-dark" style="white-space:pre-wrap;"><?= htmlspecialchars($besoin['description']) ?></div>
                        <?php endif; ?>

                        <?php if(!empty($besoin['fichier'])): ?>
                            <div class="mt-4 pt-3 border-top">
                                <h6 class="fw-bold mb-2">Pièce jointe</h6>
                                <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-paperclip me-2"></i>Voir le document</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-lg position-sticky" style="top: 20px;">
                    <div class="card-header bg-primary text-white py-3"><h5 class="card-title mb-0 fw-bold"><i class="bi bi-shield-check me-2"></i>Décision Financière</h5></div>
                    <div class="card-body p-4">
                        <?php if($besoin['statut'] === 'En attente de Finance'): ?>
                            <div class="alert alert-primary small border-0 bg-opacity-10 text-primary mb-4"><i class="bi bi-info-circle-fill me-1"></i> Action requise sur le budget.</div>
                            <div class="d-grid gap-3">
                                <form method="POST">
                                    <input type="hidden" name="action" value="valider">
                                    <button type="submit" class="btn btn-success fw-bold py-2 w-100 shadow-sm" onclick="return confirm('Confirmer le budget ?')"><i class="bi bi-check-circle-fill me-2"></i>Approuver</button>
                                </form>
                                <button type="button" class="btn btn-warning fw-bold py-2 w-100 shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#correctionModal"><i class="bi bi-pencil-square me-2"></i>Corriger</button>
                                <button type="button" class="btn btn-outline-danger fw-bold py-2 w-100" data-bs-toggle="modal" data-bs-target="#rejectModal"><i class="bi bi-x-circle me-2"></i>Rejeter</button>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 opacity-50">
                                <i class="bi bi-lock-fill fs-1 mb-2"></i>
                                <h6 class="">Dossier traité</h6>
                                <p class="small mb-0">Aucune action requise.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="correctionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark"><h5 class="modal-title fw-bold">Demander une correction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body bg-light">
                    <input type="hidden" name="action" value="corriger">
                    <div class="mb-3"><label class="form-label fw-bold">Motif de la correction :</label><textarea name="motif" class="form-control" rows="3" required placeholder="Ex: Erreur d'imputation..."></textarea></div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-warning fw-bold">Envoyer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title fw-bold">Rejet définitif</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body bg-light">
                    <input type="hidden" name="action" value="rejeter">
                    <div class="mb-3"><label class="form-label fw-bold">Motif du refus :</label><textarea name="motif" class="form-control" rows="3" required placeholder="Ex: Budget épuisé..."></textarea></div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-danger fw-bold">Confirmer</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>