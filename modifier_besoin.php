<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if (in_array(strtolower($_SESSION['role']), ['comptable', 'logisticien'])) { header('Location: login.php'); exit(); }

$besoin_id = $_GET['id'] ?? $_POST['id'] ?? null;
$utilisateur_id = $_SESSION['user_id'];

if (!$besoin_id) { header('Location: chef_projet.php'); exit(); }

// --- TRAITEMENT DU FORMULAIRE DE MODIFICATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre = trim($_POST['besoinTitre']);
    $description = trim($_POST['besoinDescription']);
    $fichier_actuel = $_POST['fichier_actuel'];
    $nouveau_fichier_nom = $fichier_actuel;

    if (isset($_FILES['besoinFichier']) && $_FILES['besoinFichier']['error'] === UPLOAD_ERR_OK) {
        $uploadFileDir = __DIR__ . '/uploads/';
        if (!empty($fichier_actuel) && file_exists($uploadFileDir . $fichier_actuel)) {
            unlink($uploadFileDir . $fichier_actuel);
        }
        
        $fileTmpPath = $_FILES['besoinFichier']['tmp_name'];
        $fileName = basename($_FILES['besoinFichier']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = 'B' . time() . '.' . $fileExtension; // Nom de fichier simple
        $destPath = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $nouveau_fichier_nom = $newFileName;
        } else {
            $_SESSION['error'] = "Erreur de sauvegarde du fichier.";
        }
    }

    if (!isset($_SESSION['error'])) {
        try {
            // MODIFICATION : On met à jour le statut à "En attente de validation" pour une nouvelle révision
            // On s'assure aussi de ne modifier que les statuts autorisés
            $sql = "UPDATE besoins SET titre = ?, description = ?, fichier = ?, statut = 'En attente de validation', motif_rejet = NULL 
                    WHERE id = ? AND utilisateur_id = ? AND statut IN ('En attente de validation', 'Correction Requise')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$titre, $description, $nouveau_fichier_nom, $besoin_id, $utilisateur_id]);
            
            $_SESSION['success'] = "Le besoin a été corrigé et soumis à nouveau pour validation.";
            header('Location: chef_projet.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur de mise à jour.";
        }
    }
}

// --- AFFICHAGE DU FORMULAIRE ---
$stmt = $pdo->prepare("SELECT * FROM besoins WHERE id = ? AND utilisateur_id = ?");
$stmt->execute([$besoin_id, $utilisateur_id]);
$besoin = $stmt->fetch();

// CORRECTION : On autorise la modification si le statut est 'En attente de validation' OU 'Correction Requise'
if (!$besoin || !in_array($besoin['statut'], ['En attente de validation', 'Correction Requise'])) {
    $_SESSION['error'] = "Ce besoin ne peut plus être modifié (statut actuel : " . htmlspecialchars($besoin['statut'] ?? 'Inconnu') . ").";
    header('Location: chef_projet.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le Besoin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h3>Modifier le besoin : <?= htmlspecialchars($besoin['titre']) ?></h3>
                    </div>
                    <div class="card-body">
                        <?php if ($besoin['statut'] === 'Correction Requise' && !empty($besoin['motif_rejet'])): ?>
                            <div class="alert alert-warning">
                                <strong>Instructions du logisticien :</strong><br>
                                <?= htmlspecialchars($besoin['motif_rejet']) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($besoin['id']) ?>">
                            <input type="hidden" name="fichier_actuel" value="<?= htmlspecialchars($besoin['fichier'] ?? '') ?>">
                            
                            <div class="mb-3">
                                <label for="besoinTitre" class="form-label">Titre du besoin</label>
                                <input type="text" class="form-control" id="besoinTitre" name="besoinTitre" value="<?= htmlspecialchars($besoin['titre']) ?>" required>
                            </div>
                            <div class="mb-3"> for="besoinDescription" class="form-label">Cadre(projet)</label>
                                <textarea class="form-control" id="besoinDescription" name="besoinDescription" rows="5" required><?= htmlspecialchars($besoin['description']) ?></textarea>
             
                                <label               </div>
                            <div class="mb-3">
                                <label for="besoinFichier" class="form-label">Changer le fichier joint</label>
                                <?php if(!empty($besoin['fichier'])): ?>
                                    <p class="form-text">Fichier actuel : <a href="uploads/<?= rawurlencode($besoin['fichier']) ?>" download><?= htmlspecialchars($besoin['fichier']) ?></a></p>
                                <?php endif; ?>
                                <input class="form-control" type="file" id="besoinFichier" name="besoinFichier">
                            </div>
                            
                            <hr>
                            <a href="chef_projet.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Enregistrer et Soumettre pour validation</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>