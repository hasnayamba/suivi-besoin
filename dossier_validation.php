<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Accès réservé au comptable ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') {
    header('Location: login.php');
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- LOGIQUE POUR LES NOTIFICATIONS ---
$notifications = [];
$unread_count = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 5");
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) { }

// --- GESTION DE L'ACTION (VALIDER FACTURE / PAYER / REJETER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marche_id'])) {
    $marche_id = $_POST['marche_id'];
    $besoin_id_associe = $_POST['besoin_id'];

    $id_demandeur = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = " . $pdo->quote($besoin_id_associe))->fetchColumn();
    $titre_besoin = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id_associe))->fetchColumn();

    // -----------------------------------------------------------
    // ACTION 1 : VALIDER LA FACTURE (Le "Bon à payer")
    // -----------------------------------------------------------
    if (isset($_POST['valider_facture'])) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE marches SET statut = 'Facture Validée' WHERE id = ?")->execute([$marche_id]);
            $pdo->prepare("UPDATE besoins SET statut = 'Facture Validée' WHERE id = ?")->execute([$besoin_id_associe]);

            if ($id_demandeur) {
                $message = "La facture de votre dossier '" . htmlspecialchars($titre_besoin) . "' a été validée par la comptabilité. En attente de paiement.";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, 'chef_projet.php')")->execute([$id_demandeur, $message]);
            }
            $pdo->commit();
            $_SESSION['success'] = "La facture a été validée ! Le dossier est maintenant en attente de paiement.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
        }

    // -----------------------------------------------------------
    // ACTION 2 : ENREGISTRER LE PAIEMENT DÉFINITIF
    // -----------------------------------------------------------
    } elseif (isset($_POST['enregistrer_paiement'])) {
        $montant_paye = !empty($_POST['montant_paye']) ? floatval($_POST['montant_paye']) : null;
        $date_paiement = !empty($_POST['date_paiement']) ? $_POST['date_paiement'] : date('Y-m-d');
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE marches SET statut = 'Paiement Approuvé', montant_paye = ?, date_paiement = ? WHERE id = ?")
                ->execute([$montant_paye, $date_paiement, $marche_id]);

            $pdo->prepare("UPDATE besoins SET statut = 'Paiement Approuvé' WHERE id = ?")->execute([$besoin_id_associe]);

            // Upload de la preuve
            if (isset($_FILES['preuve_paiement']) && $_FILES['preuve_paiement']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['preuve_paiement'];
                $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (in_array($fileExt, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $newFileName = 'PREUVE_' . $marche_id . '_' . time() . '.' . $fileExt;
                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFileName)) {
                        $doc_id = 'DOC_' . time() . rand(100, 999);
                        $pdo->prepare("INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, 'Preuve de Paiement', ?, ?)")
                            ->execute([$doc_id, $marche_id, $date_paiement, $newFileName]);
                    }
                }
            }

            if ($id_demandeur) {
                $message = "Le paiement de votre dossier '" . htmlspecialchars($titre_besoin) . "' a été exécuté.";
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, 'chef_projet.php')")->execute([$id_demandeur, $message]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Le paiement a été enregistré avec succès et le dossier est clôturé.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
        }

    // -----------------------------------------------------------
    // ACTION 3 : REJETER LE DOSSIER
    // -----------------------------------------------------------
    } elseif (isset($_POST['rejeter']) && !empty($_POST['motif_rejet'])) {
        $motif = trim($_POST['motif_rejet']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE marches SET statut = 'Rejeté par Comptable', motif_rejet = ? WHERE id = ?")->execute([$motif, $marche_id]);
            $pdo->prepare("UPDATE besoins SET statut = 'Rejeté par Comptable' WHERE id = ?")->execute([$besoin_id_associe]);
            
            $logisticiens = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'logisticien'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($logisticiens as $log_id) {
                $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)")
                    ->execute([$log_id, "Dossier $marche_id rejeté. Motif: $motif", "gerer_marche.php?id=$marche_id"]);
            }
            $pdo->commit();
            $_SESSION['error'] = "Le dossier a été rejeté vers la logistique.";
        } catch (PDOException $e) {
            $pdo->rollBack();
        }
    }
    header('Location: dossier_validation.php?besoin_id=' . $besoin_id_associe);
    exit();
}

// --- RÉCUPÉRATION DES DONNÉES DU DOSSIER ---
$besoin_id = $_GET['besoin_id'] ?? null;
if (!$besoin_id) { header('Location: comptable_dashboard.php'); exit(); }

try {
    $stmt_besoin = $pdo->prepare("SELECT b.*, u.nom as demandeur FROM besoins b LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id WHERE b.id = ?");
    $stmt_besoin->execute([$besoin_id]);
    $besoin = $stmt_besoin->fetch();

    $stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE besoin_id = ?");
    $stmt_marche->execute([$besoin_id]);
    $marche = $stmt_marche->fetch();
    $marche_id = $marche['id'] ?? null;
    $type_procedure = $marche['type_procedure'] ?? 'Standard';

    $stmt_dp = $pdo->prepare("SELECT * FROM demandes_proforma WHERE besoin_id = ?");
    $stmt_dp->execute([$besoin_id]);
    $demande_proforma = $stmt_dp->fetch();

    $proformas_recues = [];
    if ($demande_proforma) {
        $stmt_pr = $pdo->prepare("SELECT * FROM proformas_recus WHERE demande_proforma_id = ? ORDER BY statut");
        $stmt_pr->execute([$demande_proforma['id']]);
        $proformas_recues = $stmt_pr->fetchAll();
    }

    $documents = [];
    if ($marche_id) {
        $stmt_docs = $pdo->prepare("SELECT * FROM documents_commande WHERE marche_id = ?");
        $stmt_docs->execute([$marche_id]);
        $documents = $stmt_docs->fetchAll();
    }
} catch (PDOException $e) { die("Erreur : " . $e->getMessage()); }

$docs_a_afficher = [
    'PV' => ['label' => 'PV de Sélection', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Bon de Commande' => ['label' => 'Bon de Commande / Contrat', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Bon de Livraison' => ['label' => 'Bon de Livraison / PV de Réception', 'requis' => ($type_procedure === 'Standard'), 'fichier' => null],
    'Dossier Client' => ['label' => 'Dossier Client (ARF, RCCM...)', 'requis' => false, 'fichier' => null],
    'Facture' => ['label' => 'Facture Finale', 'requis' => true, 'fichier' => null],
    'Preuve de Paiement' => ['label' => 'Preuve de Paiement (OV, Chèque)', 'requis' => false, 'fichier' => null]
];
foreach ($documents as $doc) {
    if (array_key_exists($doc['type_document'], $docs_a_afficher)) {
        $docs_a_afficher[$doc['type_document']]['fichier'] = $doc['fichier_joint'];
    }
}
if ($type_procedure === 'Achat Direct') $docs_a_afficher['PV']['requis'] = false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Validation du Dossier - Comptabilité</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <nav class="sidebar bg-white border-end" style="width: 260px;">
             <div class="p-4 border-bottom"><h5 class="mb-1">Comptabilité</h5></div>
            <div class="p-3">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                    <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                    <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
                </ul>
            </div>
        </nav>

        <div class="flex-fill d-flex flex-column main-content overflow-auto">
            <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between">
                <div><h2 class="mb-1 fw-bold text-dark"><i class="bi bi-folder-check me-2 text-primary"></i>Dossier de Validation</h2></div>
                <a href="comptable_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Retour</a>
            </header>

            <main class="p-4">
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-4">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-white"><h6 class="mb-0 fw-bold text-muted">1. Origine du Besoin</h6></div>
                            <div class="card-body">
                                <p><strong>Titre:</strong> <?= htmlspecialchars($besoin['titre']) ?></p>
                                <p><strong>Initiateur:</strong> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?></p>
                            </div>
                        </div>

                         <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-white"><h6 class="mb-0 fw-bold text-muted">3. Données Financières</h6></div>
                            <div class="card-body">
                                <p><strong>Fournisseur:</strong> <?= htmlspecialchars($marche['fournisseur'] ?? 'N/A') ?></p>
                                <p><strong>Montant Facturé:</strong> <span class="fw-bold text-success fs-5"><?= number_format($marche['montant'] ?? 0, 0, ',', ' ') . ' CFA' ?></span></p>
                                <hr>
                                <p><strong>Statut Logistique:</strong> <span class="badge bg-dark"><?= htmlspecialchars($marche['statut'] ?? 'N/A') ?></span></p>
                                
                                <?php if($marche['statut'] === 'Paiement Approuvé'): ?>
                                    <div class="mt-3 p-2 bg-light border-success border-start border-4">
                                        <p class="small mb-0">Payé le : <?= date('d/m/Y', strtotime($marche['date_paiement'])) ?><br>Montant : <strong class="text-success"><?= number_format($marche['montant_paye'], 0, ',', ' ') ?> CFA</strong></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-white"><h6 class="mb-0 fw-bold text-dark"><i class="bi bi-files me-2 text-primary"></i>Pièces Justificatives</h6></div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($docs_a_afficher as $doc_info): ?>
                                        <?php if (!$doc_info['requis'] && empty($doc_info['fichier'])) continue; ?>
                                         <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                            <span class="fw-bold"><?= htmlspecialchars($doc_info['label']) ?></span>
                                            <?php if (!empty($doc_info['fichier'])): ?>
                                                <a href="uploads/<?= rawurlencode($doc_info['fichier']) ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i> Ouvrir</a>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Manquante</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>

                         <div class="card border-0 shadow-lg mt-5">
                            <div class="card-body p-4 text-center">
                                <?php if ($marche && $marche['statut'] === 'Facturé'): ?>
                                    <h5 class="fw-bold mb-3">Étape 1 : Validation de la Facture</h5>
                                    <p class="text-muted small mb-4">Avez-vous vérifié la conformité des documents ? Donnez le "Bon à payer".</p>
                                    <button type="button" class="btn btn-outline-danger px-4 me-3" data-bs-toggle="modal" data-bs-target="#rejetModal">Rejeter</button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                                        <button type="submit" name="valider_facture" class="btn btn-primary px-5 fw-bold"><i class="bi bi-check-all me-2"></i>Valider la Facture</button>
                                    </form>

                                <?php elseif ($marche && $marche['statut'] === 'Facture Validée'): ?>
                                    <h5 class="fw-bold mb-3 text-success">Étape 2 : Décaissement</h5>
                                    <p class="text-muted small mb-4">La facture est validée. Cliquez ci-dessous lorsque le virement ou le chèque a été émis.</p>
                                    <button type="button" class="btn btn-success btn-lg px-5 fw-bold shadow" data-bs-toggle="modal" data-bs-target="#approuverModal">
                                        <i class="bi bi-cash-coin me-2"></i> Enregistrer le Paiement
                                    </button>
                                    
                                <?php elseif($marche && $marche['statut'] === 'Rejeté par Comptable'): ?>
                                    <h5 class="text-danger"><i class="bi bi-x-circle me-2"></i>Dossier Rejeté</h5>
                                <?php elseif($marche && $marche['statut'] === 'Paiement Approuvé'): ?>
                                    <h5 class="text-success"><i class="bi bi-check-circle-fill me-2"></i>Paiement effectué et dossier clos.</h5>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="approuverModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-success text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2"></i>Détails du Paiement</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Montant effectivement décaissé (CFA) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control form-control-lg text-success fw-bold" name="montant_paye" value="<?= htmlspecialchars($marche['montant'] ?? 0) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Date d'exécution <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_paiement" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold small text-primary">Preuve (Chèque, OV) (Optionnel)</label>
                            <input class="form-control border-primary" type="file" name="preuve_paiement" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="enregistrer_paiement" class="btn btn-success fw-bold px-4">Confirmer le Paiement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Rejeter le Dossier</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="marche_id" value="<?= htmlspecialchars($marche_id) ?>">
                        <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>"> 
                        <label class="form-label fw-bold">Motif du rejet :</label>
                        <textarea class="form-control border-danger" name="motif_rejet" rows="4" required></textarea>
                    </div>
                    <div class="modal-footer bg-white border-0">
                        <button type="submit" name="rejeter" class="btn btn-danger px-4">Confirmer le Rejet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>