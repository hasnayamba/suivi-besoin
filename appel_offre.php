<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$besoin_id = $_GET['besoin_id'] ?? $_POST['besoin_id'] ?? null;
if (!$besoin_id) {
    header('Location: besoins_logisticien.php');
    exit();
}

// --- TRAITEMENT DU FORMULAIRE DE LANCEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appel_offre'])) {
    $date_limite = trim($_POST['date_limite']);
    $canal = trim($_POST['canal_publication']);

    // Validation
    if (empty($date_limite) || empty($canal) || !isset($_FILES['dossier_ao']) || $_FILES['dossier_ao']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Veuillez remplir tous les champs et joindre le dossier d'appel d'offres.";
    } else {
        // Gestion du fichier uploadé
        $file = $_FILES['dossier_ao'];
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        
        // Sécurité des extensions
        $allowed_extensions = ['zip', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (in_array($fileExtension, $allowed_extensions)) {
            
            $newFileName = 'AO_' . $besoin_id . '_' . time() . '.' . $fileExtension;
            
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                
                try {
                    $pdo->beginTransaction();

                    // 1. Insérer dans la nouvelle table 'appels_offre'
                    $ao_id = 'AO' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
                    $sql_ao = "INSERT INTO appels_offre (id, besoin_id, dossier_ao, date_limite, canal_publication, statut) 
                               VALUES (?, ?, ?, ?, ?, 'Lancé')";
                    $stmt_ao = $pdo->prepare($sql_ao);
                    $stmt_ao->execute([$ao_id, $besoin_id, $newFileName, $date_limite, $canal]);
                    
                    // 2. Mettre à jour le statut du besoin initial
                    // NOTE: On ne change PAS le 'type_demande' pour garder la distinction Fourniture/Service
                    $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Appel d\'offres lancé' WHERE id = ?");
                    $stmt_besoin->execute([$besoin_id]);

                    // 3. Notification au demandeur
                    // On récupère l'ID du demandeur
                    $stmt_demandeur = $pdo->prepare("SELECT utilisateur_id, titre FROM besoins WHERE id = ?");
                    $stmt_demandeur->execute([$besoin_id]);
                    $infos_besoin = $stmt_demandeur->fetch();

                    if ($infos_besoin) {
                        $msg_notif = "Appel d'Offres lancé pour votre dossier : " . $infos_besoin['titre'];
                        $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                        $stmt_notif->execute([$infos_besoin['utilisateur_id'], $msg_notif, "chef_projet.php"]);
                    }

                    $pdo->commit();
                    $_SESSION['success'] = "L'appel d'offres a été lancé avec succès !";
                    header('Location: besoins_logisticien.php'); // Retour à la liste
                    exit();

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Erreur lors de la sauvegarde du dossier sur le serveur.";
            }
        } else {
            $_SESSION['error'] = "Format de fichier non autorisé pour le DAO.";
        }
    }
    // En cas d'erreur, on reste sur la page
    header('Location: lancer_appel_offre.php?besoin_id=' . $besoin_id);
    exit();
}

// --- AFFICHAGE DU FORMULAIRE (GET) ---
try {
    $stmt = $pdo->prepare("
        SELECT b.*, u.nom AS demandeur, p.nom AS projet_nom 
        FROM besoins b 
        LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
        LEFT JOIN projets p ON b.projet_id = p.id 
        WHERE b.id = ? 
        -- On vérifie que le statut est valide pour lancer un AO
        AND (b.statut = 'Validé' OR b.statut = 'En attente de la logistique')
    ");
    $stmt->execute([$besoin_id]);
    $besoin = $stmt->fetch();
    
    if (!$besoin) {
        $_SESSION['error'] = "Ce besoin n'est pas éligible à un appel d'offres ou a déjà été traité.";
        header('Location: besoins_logisticien.php');
        exit();
    }
    
    // Récupération des articles pour l'affichage conditionnel dans le modal
    $stmt_art = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
    $stmt_art->execute([$besoin_id]);
    $articles = $stmt_art->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Erreur de chargement: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lancer un Appel d'Offres - Logistique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>
    
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div>
                <h3 class="h5 mb-0 fw-bold"><i class="bi bi-megaphone-fill text-dark me-2"></i>Lancer un Appel d'Offres</h3>
                <small class="text-muted">Procédure ouverte pour marchés importants</small>
            </div>
            <a href="view_besoin.php?id=<?= htmlspecialchars($besoin_id) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-2"></i>Retour au dossier</a>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-9">
                    
                    <div class="alert alert-dark shadow-sm border-dark d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="alert-heading fw-bold mb-1"><?= htmlspecialchars($besoin['titre']) ?></h5>
                            <span class="small">Montant approuvé par la Finance : <strong><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</strong></span>
                        </div>
                        <button type="button" class="btn btn-dark fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalBesoinDetails">
                            <i class="bi bi-eye me-2"></i>Voir ce qu'on doit publier
                        </button>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white border-bottom pt-4 pb-3 px-4">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-card-checklist me-2"></i>Détails de la publication</h5>
                        </div>
                        <div class="card-body p-4 p-md-5">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                                
                                <div class="mb-4">
                                    <label for="dossier_ao" class="form-label fw-bold small text-muted">DOSSIER D'APPEL D'OFFRES (DAO) <span class="text-danger">*</span></label>
                                    <input class="form-control form-control-lg border-primary bg-light" type="file" id="dossier_ao" name="dossier_ao" accept=".zip,.rar,.pdf,.doc,.docx,.xls,.xlsx" required>
                                    <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Veuillez joindre le dossier complet contenant le règlement, les TDR et les annexes techniques (Privilégiez une archive ZIP).</div>
                                </div>
                                
                                <div class="row g-4 mt-2">
                                    <div class="col-md-6">
                                        <label for="date_limite" class="form-label fw-bold small text-muted">DATE LIMITE DE SOUMISSION <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control form-control-lg border-danger bg-light text-danger fw-bold" id="date_limite" name="date_limite" min="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="canal_publication" class="form-label fw-bold small text-muted">CANAL DE PUBLICATION PRÉVU <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg bg-light" id="canal_publication" name="canal_publication" placeholder="Ex: Journal ONEP, Site web officiel, Email..." required>
                                    </div>
                                </div>
                                
                                <div class="text-end mt-5 border-top pt-4">
                                    <button type="submit" name="submit_appel_offre" class="btn btn-dark btn-lg fw-bold px-5 shadow" onclick="return confirm('Confirmez-vous le lancement officiel de cet Appel d\'Offres ?')">
                                        <i class="bi bi-megaphone-fill me-2"></i> Lancer l'Appel d'Offres
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="modalBesoinDetails" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2"></i>Détails du marché à lancer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                
                <div class="row mb-4">
                    <div class="col-sm-6">
                        <span class="text-muted small text-uppercase fw-bold">Initiateur :</span><br>
                        <i class="bi bi-person text-dark"></i> <?= htmlspecialchars($besoin['demandeur'] ?? 'N/A') ?>
                    </div>
                    <div class="col-sm-6 text-end">
                        <span class="text-muted small text-uppercase fw-bold">Imputation :</span><br>
                        <span class="badge bg-secondary"><?= htmlspecialchars($besoin['projet_nom'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <?php if (!empty($articles)): ?>
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Liste des articles </h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-sm mb-0">
                                    <thead class="table-light small text-muted">
                                        <tr>
                                            <th>Désignation</th>
                                            <th class="text-center">Quantité</th>
                                            <th class="text-end">Prix Unitaire Estimé</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($articles as $art): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($art['designation']) ?></td>
                                                <td class="text-center fw-bold text-dark"><?= htmlspecialchars($art['quantite']) ?> <span class="fw-normal text-muted"><?= htmlspecialchars($art['unite']) ?></span></td>
                                                <td class="text-end"><?= number_format($art['pu_indicatif'], 0, ',', ' ') ?></td>
                                                <td class="text-end text-success fw-bold"><?= number_format($art['prix_total'], 0, ',', ' ') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="3" class="text-end text-uppercase">Budget Validé :</th>
                                            <th class="text-end text-dark fs-6"><?= number_format($besoin['montant'], 0, ',', ' ') ?> CFA</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Description du besoin / TDR</h6>
                            <p class="text-dark" style="white-space: pre-wrap;"><?= htmlspecialchars($besoin['description'] ?? 'Aucune description.') ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($besoin['fichier'])): ?>
                    <div class="text-center mt-4">
                        <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill px-4 shadow-sm">
                            <i class="bi bi-file-earmark-pdf-fill me-2"></i>Ouvrir la note conceptuelle du demandeur
                        </a>
                    </div>
                <?php endif; ?>

            </div>
            <div class="modal-footer bg-white border-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>