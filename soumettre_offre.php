<?php
// ATTENTION : Pas besoin de session logisticien ici, c'est une page PUBLIQUE pour les fournisseurs.
include 'db_connect.php';

$token = $_GET['token'] ?? null;
$message_success = "";
$message_error = "";

if (!$token) {
    die("<h2 style='text-align:center; color:red; margin-top:50px;'>Accès refusé. Lien invalide ou expiré.</h2>");
}

// 1. VÉRIFIER LE TOKEN DANS LA BASE DE DONNÉES
$stmt = $pdo->prepare("
    SELECT pf.*, dp.titre_besoin, dp.delai_reponse, f.nom as fournisseur_nom, f.id as fournisseur_id 
    FROM proforma_fournisseurs pf
    JOIN demandes_proforma dp ON pf.proforma_id = dp.id
    JOIN fournisseurs f ON pf.fournisseur_id = f.id
    WHERE pf.token = ?
");
$stmt->execute([$token]);
$lien_info = $stmt->fetch();

if (!$lien_info) {
    die("<h2 style='text-align:center; color:red; margin-top:50px;'>Jeton de sécurité introuvable ou invalide.</h2>");
}

// Vérifier si le fournisseur a DÉJÀ répondu
if ($lien_info['statut_reponse'] === 'Reçu') {
    $deja_soumis = true;
} else {
    $deja_soumis = false;
}

// 2. TRAITEMENT DE LA SOUMISSION DU FORMULAIRE PAR LE FOURNISSEUR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$deja_soumis) {
    $montant = trim($_POST['montant']);
    $delai = trim($_POST['delai']);
    $fichier_nom = null;

    if (empty($montant) || empty($_FILES['fichier_proforma']['name'])) {
        $message_error = "Veuillez remplir le montant et joindre votre fichier PDF/Image.";
    } else {
        // Upload de la proforma
        $ext = strtolower(pathinfo($_FILES['fichier_proforma']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'png', 'jpeg'])) {
            $uploadFileDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
            
            $clean_fournisseur = preg_replace('/[^A-Za-z0-9\-]/', '_', $lien_info['fournisseur_nom']);
            $newFileName = 'PROFORMA_' . $lien_info['proforma_id'] . '_' . $clean_fournisseur . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['fichier_proforma']['tmp_name'], $uploadFileDir . $newFileName)) {
                $fichier_nom = $newFileName;
                
                try {
                    $pdo->beginTransaction();

                    // Insérer dans les offres reçues
                    $sql_insert = "INSERT INTO proformas_recus (demande_proforma_id, fournisseur, montant, delai, date_reception, fichier, statut) 
                                   VALUES (?, ?, ?, ?, CURDATE(), ?, 'En attente')";
                    $stmt_in = $pdo->prepare($sql_insert);
                    $stmt_in->execute([
                        $lien_info['proforma_id'], 
                        $lien_info['fournisseur_nom'], 
                        $montant, 
                        $delai, 
                        $fichier_nom
                    ]);

                    // Mettre à jour le statut du lien (Désactiver le token/Marquer comme reçu)
                    $stmt_up_lien = $pdo->prepare("UPDATE proforma_fournisseurs SET statut_reponse = 'Reçu' WHERE token = ?");
                    $stmt_up_lien->execute([$token]);

                    // Mettre à jour la demande globale
                    $stmt_up_dp = $pdo->prepare("UPDATE demandes_proforma SET statut = 'Réponses en cours' WHERE id = ? AND statut = 'En attente'");
                    $stmt_up_dp->execute([$lien_info['proforma_id']]);

                    $pdo->commit();
                    $deja_soumis = true; // Pour changer l'affichage
                    $message_success = "Votre offre a été transmise avec succès à l'équipe logistique. Merci !";

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $message_error = "Erreur technique lors de l'enregistrement : " . $e->getMessage();
                }
            } else {
                $message_error = "Erreur lors de l'envoi de votre fichier.";
            }
        } else {
            $message_error = "Format de fichier non autorisé (Seuls PDF, JPG, PNG sont acceptés).";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portail Fournisseur - Soumettre une Offre</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .logo-box { text-align: center; margin-bottom: 30px; }
        .card-form { border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-5">

    <div class="container" style="max-width: 600px;">
        <div class="logo-box">
            <h2 class="text-primary fw-bold"><i class="bi bi-shield-check me-2"></i>Swisscontact</h2>
            <p class="text-muted">Portail de soumission des offres</p>
        </div>

        <?php if ($message_success): ?>
            <div class="alert alert-success shadow-sm rounded-3 p-4 text-center">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                <h4 class="mt-3">Offre bien reçue !</h4>
                <p class="mb-0"><?= $message_success ?></p>
            </div>
        <?php endif; ?>

        <?php if ($message_error): ?>
            <div class="alert alert-danger shadow-sm rounded-3">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $message_error ?>
            </div>
        <?php endif; ?>

        <?php if ($deja_soumis && empty($message_success)): ?>
            <div class="alert alert-info shadow-sm rounded-3 p-4 text-center">
                <i class="bi bi-info-circle-fill text-info" style="font-size: 3rem;"></i>
                <h4 class="mt-3">Lien expiré</h4>
                <p class="mb-0">Vous avez déjà soumis votre offre pour cette demande de cotation. Ce lien n'est plus actif.</p>
            </div>
        <?php elseif (!$deja_soumis): ?>
            
            <div class="card card-form">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold text-dark">Détails de la demande</h5>
                    <div class="bg-light p-3 rounded mt-3 small">
                        <strong>Référence :</strong> <?= htmlspecialchars($lien_info['proforma_id']) ?><br>
                        <strong>Besoin :</strong> <?= htmlspecialchars($lien_info['titre_besoin']) ?><br>
                        <strong>Date limite :</strong> <span class="text-danger fw-bold"><?= date('d/m/Y', strtotime($lien_info['delai_reponse'])) ?></span><br>
                        <strong>Entreprise :</strong> <?= htmlspecialchars($lien_info['fournisseur_nom']) ?>
                    </div>
                </div>

                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Montant Total Proposé (CFA) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control form-control-lg bg-light" name="montant" required placeholder="Ex: 1500000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Délai de livraison estimé</label>
                            <input type="text" class="form-control bg-light" name="delai" placeholder="Ex: 5 jours, 2 semaines...">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Votre document Proforma signé (PDF) <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" name="fichier_proforma" accept=".pdf,.jpg,.png,.jpeg" required>
                        </div>

                        <div class="alert alert-warning small">
                            <i class="bi bi-lock-fill me-1"></i> En cliquant sur envoyer, votre offre sera directement transmise à notre système. <strong>Cette action est définitive.</strong>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                            <i class="bi bi-send-fill me-2"></i> Soumettre mon offre
                        </button>
                    </form>
                </div>
            </div>

        <?php endif; ?>
        
        <div class="text-center mt-4">
            <small class="text-muted">&copy; <?= date('Y') ?> Swisscontact - Processus d'Achat Sécurisé</small>
        </div>
    </div>

</body>
</html>