<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$besoin_id = $_GET['id'] ?? null;
if (!$besoin_id) {
    $_SESSION['error'] = "Aucun identifiant de besoin spécifié.";
    header('Location: besoins_logisticien.php');
    exit();
}

// =========================================================================
// GESTION DU TRAITEMENT (POST) - ÉTAPE 1 ET ÉTAPE 2
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        $pdo->beginTransaction();

        // ---------------------------------------------------------
        // ACTIONS DE L'ÉTAPE 1 : VALIDER, CORRIGER, REJETER
        // ---------------------------------------------------------
        if (isset($_POST['action_valider'])) {
            $stmt = $pdo->prepare("UPDATE besoins SET statut = 'Validé', motif_rejet = NULL WHERE id = ?");
            $stmt->execute([$besoin_id]);
            
            $initiateur_id = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = '$besoin_id'")->fetchColumn();
            if ($initiateur_id) {
                $msg = "Votre demande d'achat ($besoin_id) a été validée par la Logistique.";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
                    ->execute([$initiateur_id, $msg, "chef_projet.php"]);
            }
            $pdo->commit();
            $_SESSION['success'] = "Besoin validé ! Veuillez maintenant choisir la procédure d'achat appropriée.";
            header("Location: view_besoin.php?id=$besoin_id");
            exit();
        }
        
        elseif (isset($_POST['action_corriger'])) {
            $motif = trim($_POST['motif_correction']);
            if (empty($motif)) throw new Exception("Le motif de correction est obligatoire.");

            $stmt = $pdo->prepare("UPDATE besoins SET statut = 'Correction Requise', motif_rejet = ? WHERE id = ?");
            $stmt->execute([$motif, $besoin_id]);
            
            $initiateur_id = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = '$besoin_id'")->fetchColumn();
            if ($initiateur_id) {
                $msg = "La Logistique a demandé une correction sur votre besoin ($besoin_id). Motif: $motif";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
                    ->execute([$initiateur_id, $msg, "chef_projet.php"]);
            }
            $pdo->commit();
            $_SESSION['warning'] = "Le dossier a été renvoyé à l'initiateur pour correction.";
            header("Location: view_besoin.php?id=$besoin_id");
            exit();
        }

        elseif (isset($_POST['action_rejeter'])) {
            $motif = trim($_POST['motif_rejet']);
            if (empty($motif)) throw new Exception("Le motif de rejet est obligatoire.");

            $stmt = $pdo->prepare("UPDATE besoins SET statut = 'Rejeté', motif_rejet = ? WHERE id = ?");
            $stmt->execute([$motif, $besoin_id]);
            
            $initiateur_id = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = '$besoin_id'")->fetchColumn();
            if ($initiateur_id) {
                $msg = "Votre demande ($besoin_id) a été rejetée par la Logistique. Motif: $motif";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
                    ->execute([$initiateur_id, $msg, "chef_projet.php"]);
            }
            $pdo->commit();
            $_SESSION['error'] = "Le dossier a été définitivement rejeté.";
            header("Location: view_besoin.php?id=$besoin_id");
            exit();
        }

        // ---------------------------------------------------------
        // ACTIONS DE L'ÉTAPE 2 : CHOIX DE LA PROCÉDURE (ROUTAGE)
        // ---------------------------------------------------------
        elseif (isset($_POST['choix_procedure'])) {
            $proc = $_POST['choix_procedure'];
            
            if ($proc === 'achat_direct') {
                $pdo->prepare("UPDATE besoins SET type_demande = 'Achat_Direct' WHERE id = ?")->execute([$besoin_id]);
                $pdo->commit();
                header("Location: achat_direct.php?besoin_id=$besoin_id");
                exit();
            } 
            elseif ($proc === 'proforma') {
                $pdo->prepare("UPDATE besoins SET type_demande = 'Standard' WHERE id = ?")->execute([$besoin_id]);
                $pdo->commit();
                header("Location: demande_proforma.php?besoin_id=$besoin_id");
                exit();
            } 
            elseif ($proc === 'ao') {
                $pdo->prepare("UPDATE besoins SET type_demande = 'Standard' WHERE id = ?")->execute([$besoin_id]);
                $pdo->commit();
                header("Location: appel_offre.php?besoin_id=$besoin_id"); 
                exit();
            }
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur : " . $e->getMessage();
    }
}

// =========================================================================
// RÉCUPÉRATION DES DÉTAILS DU DOSSIER
// =========================================================================
$stmt = $pdo->prepare("
    SELECT b.*, u.nom AS demandeur, p.nom AS projet_nom 
    FROM besoins b 
    LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
    LEFT JOIN projets p ON b.projet_id = p.id 
    WHERE b.id = ?
");
$stmt->execute([$besoin_id]);
$besoin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$besoin) {
    die("Besoin introuvable.");
}

$stmt_art = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
$stmt_art->execute([$besoin_id]);
$articles = $stmt_art->fetchAll(PDO::FETCH_ASSOC);

// Détection des procédures déjà en cours
$proforma_id = $pdo->query("SELECT id FROM demandes_proforma WHERE besoin_id = '$besoin_id' ORDER BY date_emission DESC LIMIT 1")->fetchColumn();
$marche_id = $pdo->query("SELECT id FROM marches WHERE besoin_id = '$besoin_id' ORDER BY id DESC LIMIT 1")->fetchColumn();
$ao_id = $pdo->query("SELECT id FROM appels_offre WHERE besoin_id = '$besoin_id' ORDER BY date_creation DESC LIMIT 1")->fetchColumn();

// Fonction pour les badges
function get_status_badge($statut) {
    $badges = [
        'En attente de la logistique' => '<span class="badge bg-warning text-dark border border-warning fs-6"><i class="bi bi-hourglass-split me-1"></i> À Traiter</span>',
        'En attente de validation' => '<span class="badge bg-warning text-dark border border-warning fs-6"><i class="bi bi-hourglass-split me-1"></i> À Traiter</span>',
        'Validé' => '<span class="badge bg-info text-dark fs-6"><i class="bi bi-check2-circle me-1"></i> Validé (Attente Procédure)</span>',
        'En cours de proforma' => '<span class="badge bg-primary fs-6"><i class="bi bi-envelope-paper me-1"></i> Proforma Lancée</span>',
        'Appel d\'offres lancé' => '<span class="badge bg-dark fs-6"><i class="bi bi-megaphone me-1"></i> Appel d\'Offres Lancé</span>',
        'Marché attribué' => '<span class="badge bg-success fs-6"><i class="bi bi-award me-1"></i> Marché Attribué</span>',
        'Facturé' => '<span class="badge bg-secondary fs-6"><i class="bi bi-receipt me-1"></i> Transmis Compta</span>',
        'Paiement Approuvé' => '<span class="badge bg-success border fs-6"><i class="bi bi-cash-coin me-1"></i> Payé</span>',
        'Correction Requise' => '<span class="badge bg-warning text-dark border fs-6"><i class="bi bi-pencil me-1"></i> En Correction</span>',
        'Rejeté' => '<span class="badge bg-danger fs-6"><i class="bi bi-x-circle me-1"></i> Rejeté</span>'
    ];
    return $badges[$statut] ?? '<span class="badge bg-secondary fs-6">' . htmlspecialchars($statut) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traitement du Besoin - Logistique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* NOUVEAU DESIGN POUR LES BOUTONS DE PROCÉDURE */
        .btn-procedure {
            background-color: #ffffff;
            border: 2px solid #f0f0f0;
            border-radius: 12px;
            transition: all 0.2s ease-in-out;
            text-align: left;
            position: relative;
            overflow: hidden;
        }
        .btn-procedure:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.08) !important;
        }
        .btn-procedure:active {
            transform: translateY(0);
        }
        /* Couleurs au survol par type */
        .btn-procedure.proc-success:hover { border-color: #198754; background-color: #f0fdf4; }
        .btn-procedure.proc-primary:hover { border-color: #0d6efd; background-color: #f0f7ff; }
        .btn-procedure.proc-dark:hover { border-color: #212529; background-color: #f8f9fa; }
        
        .icon-box-proc {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'header.php'; ?>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div>
                <h3 class="h5 mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-2"></i>Détails du Dossier d'Achat</h3>
                <small class="text-muted">Réf : <?= htmlspecialchars($besoin['id']) ?></small>
            </div>
            <a href="besoins_logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour au tableau</a>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['warning']; unset($_SESSION['warning']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm"><i class="bi bi-x-circle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white pb-0 border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                            <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($besoin['titre']) ?></h4>
                            <div class="text-end">
                                <span class="d-block text-muted small mb-1">Statut actuel :</span>
                                <?= get_status_badge($besoin['statut']) ?>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row mb-4 bg-light p-3 rounded border">
                                <div class="col-md-4 mb-2">
                                    <strong class="text-muted small text-uppercase">Initiateur</strong><br>
                                    <i class="bi bi-person-fill text-primary me-1"></i> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <strong class="text-muted small text-uppercase">Imputation (Projet)</strong><br>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($besoin['projet_nom'] ?? 'Non défini') ?></span>
                                </div>
                                <div class="col-md-4 mb-2 text-end">
                                    <strong class="text-muted small text-uppercase">Montant validé Finance</strong><br>
                                    <span class="text-success fw-bold fs-5"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</span>
                                </div>
                            </div>

                            <h6 class="fw-bold border-bottom pb-2 text-primary">Contexte / Justification</h6>
                            <p class="mb-4 text-dark" style="white-space: pre-wrap;"><?= htmlspecialchars($besoin['description'] ?? 'Aucune description.') ?></p>

                            <?php if (!empty($articles)): ?>
                                <h6 class="fw-bold border-bottom pb-2 text-primary">Articles demandés</h6>
                                <div class="table-responsive mb-4">
                                    <table class="table table-bordered table-sm align-middle">
                                        <thead class="table-light text-muted small">
                                            <tr>
                                                <th>Désignation</th>
                                                <th>Qté</th>
                                                <th>Unité</th>
                                                <th>PU Ind.</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($articles as $art): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($art['designation']) ?></td>
                                                    <td class="fw-bold text-center"><?= htmlspecialchars($art['quantite']) ?></td>
                                                    <td><?= htmlspecialchars($art['unite']) ?></td>
                                                    <td><?= number_format($art['pu_indicatif'], 0, ',', ' ') ?></td>
                                                    <td class="text-success fw-bold"><?= number_format($art['prix_total'], 0, ',', ' ') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($besoin['fichier'])): ?>
                                <div class="bg-light p-3 rounded border text-center mt-4">
                                    <i class="bi bi-file-earmark-pdf text-danger fs-2 d-block mb-2"></i>
                                    <h6 class="fw-bold">Document rattaché par le demandeur</h6>
                                    <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" target="_blank" class="btn btn-outline-primary btn-sm mt-1">
                                        <i class="bi bi-download me-1"></i>Télécharger le fichier joint
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    
                    <?php 
                    // ---------------------------------------------------------
                    // ÉTAPE 1 : PREMIER CONTACT -> VALIDER / CORRIGER / REJETER
                    // ---------------------------------------------------------
                    if (in_array($besoin['statut'], ['En attente de la logistique', 'En attente de validation'])): ?>
                        
                        <div class="card shadow border-warning">
                            <div class="card-header bg-warning text-dark fw-bold pt-3 pb-2">
                                <i class="bi bi-shield-check me-2"></i>1. Analyse du dossier
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-4">Examinez la demande. Si tout est correct, validez-la pour débloquer les procédures d'achat.</p>

                                <form method="POST" class="mb-3">
                                    <button type="submit" name="action_valider" class="btn btn-success w-100 fw-bold btn-lg shadow-sm" onclick="return confirm('Confirmer la validation de ce besoin ?')">
                                        <i class="bi bi-check-circle-fill me-2"></i>Valider
                                    </button>
                                </form>

                                <button type="button" class="btn btn-warning text-dark fw-bold w-100 mb-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCorriger">
                                    <i class="bi bi-pencil-square me-2"></i>Correction requise
                                </button>

                                <button type="button" class="btn btn-outline-danger fw-bold w-100" data-bs-toggle="modal" data-bs-target="#modalRejeter">
                                    <i class="bi bi-x-circle me-2"></i>Rejeter
                                </button>
                            </div>
                        </div>

                    <?php 
                    // ---------------------------------------------------------
                    // ÉTAPE 2 : LE DOSSIER EST VALIDÉ -> CHOIX DE LA PROCÉDURE (DESIGN REVISITÉ)
                    // ---------------------------------------------------------
                    elseif ($besoin['statut'] === 'Validé'): ?>
                        <div class="card shadow border-0" style="border-top: 4px solid #0d6efd !important;">
                            <div class="card-header bg-white text-dark fw-bold pt-4 pb-2 border-0">
                                <h5 class="mb-0 fw-bold"><i class="bi bi-gear-fill text-primary me-2"></i>2. Lancer la procédure</h5>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-4">Le besoin a été vérifié. Veuillez sélectionner la méthode d'achat à appliquer :</p>
                                
                                <form method="POST">
                                    
                                    <button type="submit" name="choix_procedure" value="achat_direct" class="btn btn-procedure proc-success w-100 p-3 mb-3 shadow-sm">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-box-proc bg-success bg-opacity-10 text-success me-3">
                                                <i class="bi bi-cart-plus-fill fs-4"></i>
                                            </div>
                                            <div>
                                                <span class="fw-bold d-block text-dark mb-1">Achat Direct</span>
                                                <small class="text-muted" style="line-height: 1.2; display: block;">Idéal pour les petits montants.</small>
                                            </div>
                                        </div>
                                    </button>

                                    <button type="submit" name="choix_procedure" value="proforma" class="btn btn-procedure proc-primary w-100 p-3 mb-3 shadow-sm">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-box-proc bg-primary bg-opacity-10 text-primary me-3">
                                                <i class="bi bi-envelope-paper-fill fs-4"></i>
                                            </div>
                                            <div>
                                                <span class="fw-bold d-block text-dark mb-1">Demande Proforma</span>
                                                <small class="text-muted" style="line-height: 1.2; display: block;">Consulter plusieurs fournisseurs via une demande de prix.</small>
                                            </div>
                                        </div>
                                    </button>

                                    <button type="submit" name="choix_procedure" value="ao" class="btn btn-procedure proc-dark w-100 p-3 shadow-sm">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-box-proc bg-dark bg-opacity-10 text-dark me-3">
                                                <i class="bi bi-megaphone-fill fs-4"></i>
                                            </div>
                                            <div>
                                                <span class="fw-bold d-block text-dark mb-1">Appel d'Offres</span>
                                                <small class="text-muted" style="line-height: 1.2; display: block;">Créer un dossier formel de publication (DAO).</small>
                                            </div>
                                        </div>
                                    </button>
                                    
                                </form>
                            </div>
                        </div>

                    <?php 
                    // ---------------------------------------------------------
                    // ÉTAPE 3 : BLOCAGES (CORRECTION OU REJET)
                    // ---------------------------------------------------------
                    elseif (in_array($besoin['statut'], ['Correction Requise', 'Rejeté'])): ?>
                        <div class="card shadow-sm border-danger">
                            <div class="card-header bg-danger text-white fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Dossier bloqué</div>
                            <div class="card-body">
                                <p class="mb-1 text-muted small">Motif :</p>
                                <p class="text-danger fw-bold"><?= nl2br(htmlspecialchars($besoin['motif_rejet'] ?? 'Non spécifié.')) ?></p>
                            </div>
                        </div>

                    <?php 
                    // ---------------------------------------------------------
                    // ÉTAPE 4 : LA PROCÉDURE EST DÉJÀ EN COURS OU TERMINÉE
                    // ---------------------------------------------------------
                    else: ?>
                        <div class="card shadow-sm border-0 bg-white">
                            <div class="card-header bg-light pb-2 pt-3 border-0">
                                <h5 class="fw-bold text-dark m-0"><i class="bi bi-diagram-3 text-secondary me-2"></i>Procédure en cours</h5>
                            </div>
                            <div class="card-body p-4 text-center">
                                
                                <?php if ($besoin['statut'] === 'En cours de proforma'): ?>
                                    <i class="bi bi-envelope-check text-primary mb-3" style="font-size: 3rem;"></i>
                                    <h6 class="fw-bold text-primary">Proformas en cours</h6>
                                    <p class="text-muted small">Les demandes ont été envoyées aux fournisseurs.</p>
                                    <?php if ($proforma_id): ?>
                                        <a href="gerer_reponses.php?id=<?= $proforma_id ?>" class="btn btn-primary w-100 fw-bold mt-2">Dépouiller les offres reçues</a>
                                    <?php else: ?>
                                        <a href="demande_proforma.php" class="btn btn-primary w-100 fw-bold mt-2">Aller aux proformas</a>
                                    <?php endif; ?>

                                <?php elseif ($besoin['statut'] === 'Appel d\'offres lancé'): ?>
                                    <i class="bi bi-megaphone text-dark mb-3" style="font-size: 3rem;"></i>
                                    <h6 class="fw-bold text-dark">Appel d'Offres Actif</h6>
                                    <p class="text-muted small">Le dossier de publication a été généré.</p>
                                    <a href="gerer_appel_offre.php?ao_id=<?= urlencode($ao_id) ?>" class="btn btn-dark w-100 fw-bold mt-2">Suivre l'Appel d'Offres</a>
                                <?php else: // Marché attribué, Facturé, Payé ?>
                                    <i class="bi bi-file-earmark-check-fill text-success mb-3" style="font-size: 3rem;"></i>
                                    <h6 class="fw-bold text-success">Dossier Traité</h6>
                                    <p class="text-muted small">Le fournisseur a été sélectionné et les documents ont été générés.</p>
                                    <?php if ($marche_id): ?>
                                        <a href="gerer_marche.php?id=<?= $marche_id ?>" class="btn btn-success w-100 fw-bold mt-2">Ouvrir le dossier d'achat</a>
                                    <?php else: ?>
                                        <a href="besoins_logisticien.php" class="btn btn-secondary w-100 fw-bold mt-2">Retour au tableau</a>
                                    <?php endif; ?>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="modalCorriger" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Demander une correction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">Le dossier sera renvoyé à l'initiateur pour modification. Précisez clairement ce qui doit être corrigé.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Que doit-il corriger ? <span class="text-danger">*</span></label>
                        <textarea class="form-control border-warning" name="motif_correction" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="action_corriger" class="btn btn-warning fw-bold text-dark px-4">Renvoyer au demandeur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRejeter" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Rejeter le dossier</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3 text-danger">Action irréversible. Le processus s'arrête ici.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Motif du rejet <span class="text-danger">*</span></label>
                        <textarea class="form-control border-danger" name="motif_rejet" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="action_rejeter" class="btn btn-danger fw-bold px-4">Rejeter définitivement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>