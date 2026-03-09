<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: contrat_dashboard.php');
    exit();
}

// 1. Récupération du contrat actuel
$stmt = $pdo->prepare("SELECT * FROM contrats WHERE id = ?");
$stmt->execute([$id]);
$contrat = $stmt->fetch();

// On bloque formellement si le contrat n'existe pas ou est définitivement fermé
if (!$contrat || in_array($contrat['statut'], ['Cloturé', 'Rupture de contrat'])) {
    header('Location: contrat_dashboard.php');
    exit();
}

// 2. Traitement du formulaire de renouvellement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nouvelle_date_fin = $_POST['nouvelle_date_fin'];
    $date_avenant = $_POST['date_avenant'];
    $description_avenant = trim($_POST['description_avenant']);
    $montant_additionnel = (float)($_POST['montant_additionnel'] ?? 0);
    
    // Concaténer la nouvelle description avec l'ancienne (s'il y en avait déjà une)
    $texte_avenant_existant = $contrat['avenant_changement'] ? $contrat['avenant_changement'] . "\n\n" : "";
    $nouveau_texte_avenant = $texte_avenant_existant . "[RENOUVELLEMENT DU " . date('d/m/Y', strtotime($date_avenant)) . "] : " . $description_avenant;

    // Nouveau montant total HT
    $nouveau_montant_ht = $contrat['montant_ht'] + $montant_additionnel;

    // Gestion de l'upload du fichier d'avenant
    $fichier_avenant = $contrat['fichier_avenant']; // On garde l'ancien par défaut
    if (isset($_FILES['fichier_avenant']) && $_FILES['fichier_avenant']['error'] === UPLOAD_ERR_OK) {
        $dossier_upload = 'uploads/';
        // Crée le dossier s'il n'existe pas
        if (!is_dir($dossier_upload)) { 
            mkdir($dossier_upload, 0777, true); 
        } 
        
        $nom_fichier = time() . '_avenant_' . basename($_FILES['fichier_avenant']['name']);
        $chemin_fichier = $dossier_upload . $nom_fichier;
        
        if (move_uploaded_file($_FILES['fichier_avenant']['tmp_name'], $chemin_fichier)) {
            $fichier_avenant = $nom_fichier; // Mise à jour avec le nouveau fichier
        }
    }

    try {
        // Mise à jour de la base de données
        $sql = "UPDATE contrats SET 
                    statut = 'En cours',
                    date_fin_prevue = ?,
                    date_avenant = ?,
                    date_fin_avenant = ?,
                    avenant_changement = ?,
                    montant_ht = ?,
                    fichier_avenant = ?
                WHERE id = ?";
                
        $stmt_update = $pdo->prepare($sql);
        $stmt_update->execute([
            $nouvelle_date_fin, 
            $date_avenant, 
            $nouvelle_date_fin, // La fin de l'avenant devient la nouvelle fin prévue globale
            $nouveau_texte_avenant, 
            $nouveau_montant_ht,
            $fichier_avenant, 
            $id
        ]);
        
        // Redirection vers la fiche du contrat pour voir le beau résultat
        header('Location: voir_contrat.php?id=' . $id . '&success=renouvelé');
        exit();
    } catch (PDOException $e) {
        $erreur = "Erreur lors du renouvellement : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renouveler Contrat - <?= htmlspecialchars($contrat['nom_fournisseur']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light pb-5">

<div class="container mt-5" style="max-width: 800px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0">Renouvellement de Contrat</h2>
        <a href="contrat_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Retour au tableau</a>
    </div>

    <?php if (isset($erreur)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $erreur ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-warning text-dark py-3">
            <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Fournisseur : <?= htmlspecialchars($contrat['nom_fournisseur']) ?></h5>
        </div>
        
        <div class="card-body p-4 bg-white">
            <div class="alert alert-info small mb-4">
                <strong>Ancienne date de fin :</strong> <?= date('d/m/Y', strtotime($contrat['date_fin_prevue'])) ?> <br>
                <strong>Montant actuel HT :</strong> <?= number_format($contrat['montant_ht'], 0, ',', ' ') ?> CFA
            </div>

            <form method="POST" enctype="multipart/form-data">
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date de signature de l'avenant <span class="text-danger">*</span></label>
                        <input type="date" name="date_avenant" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Nouvelle Date de Fin (Échéance) <span class="text-danger">*</span></label>
                        <input type="date" name="nouvelle_date_fin" class="form-control" required>
                        <small class="text-muted">Cette date remplacera l'ancienne fin prévue.</small>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold">Montant HT ajouté (Optionnel)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="montant_additionnel" class="form-control" placeholder="Ex: 500000" min="0">
                            <span class="input-group-text">CFA</span>
                        </div>
                        <small class="text-muted">Laissez vide ou à 0 si le renouvellement n'implique pas de frais supplémentaires (Ce montant s'additionnera au budget existant).</small>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Détails de l'Avenant / Modifications <span class="text-danger">*</span></label>
                    <textarea name="description_avenant" class="form-control" rows="4" placeholder="Ex: Prolongation de 6 mois pour maintenance, ajout d'une prestation de support..." required></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Joindre le document scanné de l'avenant (Optionnel, PDF/Image)</label>
                    <input type="file" name="fichier_avenant" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    <?php if(!empty($contrat['fichier_avenant'])): ?>
                        <small class="text-warning mt-1 d-block"><i class="bi bi-info-circle me-1"></i>Un fichier avenant existe déjà. Uploader un nouveau fichier le remplacera.</small>
                    <?php endif; ?>
                </div>

                <hr class="my-4">
                
                <div class="d-flex justify-content-end gap-2">
                    <a href="contrat_dashboard.php" class="btn btn-light border">Annuler</a>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-check-lg me-1"></i> Valider le Renouvellement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>