<?php
session_start();
include 'db_connect.php'; // Inclure la connexion PDO

// --- SIMULATION DE L'UTILISATEUR CONNECTÉ ---
$utilisateur_id = 1; // ID de l'utilisateur pour vérifier les droits

// --------------------------------------------------------
// --- A. TRAITEMENT DU FORMULAIRE DE MODIFICATION (UPDATE)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_besoin'])) {
    $besoin_id = $_POST['besoinId'];
    $titre = trim($_POST['besoinTitre']);
    $description = trim($_POST['besoinDescription']);
    
    $fichier_nom_actuel = $_POST['fichierActuel'];
    $nouveau_fichier_nom = $fichier_nom_actuel; // Par défaut, on garde l'ancien nom

    if (empty($besoin_id) || empty($titre) || empty($description)) {
        $_SESSION['error'] = "Veuillez remplir tous les champs obligatoires.";
        // Rediriger vers la page d'édition avec l'ID
        header("Location: edit_besoin.php?id=" . urlencode($besoin_id));
        exit();
    }

    try {
        // Gestion du nouveau fichier (téléversement)
        if (isset($_FILES['besoinNouveauFichier']) && $_FILES['besoinNouveauFichier']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['besoinNouveauFichier']['tmp_name'];
            $fileName = $_FILES['besoinNouveauFichier']['name'];
            $fileNameCmps = explode(".", $fileName);
            $fileExtension = strtolower(end($fileNameCmps));

            $allowedExtensions = array('pdf', 'doc', 'docx');
            
            if (in_array($fileExtension, $allowedExtensions)) {
                
                // Supprimer l'ancien fichier s'il existe
                if (!empty($fichier_nom_actuel) && $fichier_nom_actuel !== 'null') {
                    $oldFilePath = './uploads/' . $fichier_nom_actuel;
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }
                
                // Téléverser le nouveau fichier
                $nouveau_fichier_nom = $besoin_id . '_' . uniqid() . '.' . $fileExtension;
                $uploadFileDir = './uploads/'; 
                $destPath = $uploadFileDir . $nouveau_fichier_nom;

                if(!move_uploaded_file($fileTmpPath, $destPath)) {
                    throw new Exception("Erreur lors du déplacement du nouveau fichier.");
                }

            } else {
                $_SESSION['error'] = "Extension de fichier non autorisée. Seuls les fichiers PDF, DOC et DOCX sont acceptés.";
                header("Location: edit_besoin.php?id=" . urlencode($besoin_id));
                exit();
            }
        } 
        // Si le champ de fichier est vide, on garde l'ancien nom ($nouveau_fichier_nom = $fichier_nom_actuel)


        // Mise à jour de la base de données
        $sql = "UPDATE besoins SET titre = :titre, description = :description, fichier = :fichier WHERE id = :id AND utilisateur_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':titre' => $titre,
            ':description' => $description,
            ':fichier' => $nouveau_fichier_nom,
            ':id' => $besoin_id,
            ':user_id' => $utilisateur_id 
        ]);

        $_SESSION['success'] = "Le besoin **" . htmlspecialchars($titre) . "** (ID: " . htmlspecialchars($besoin_id) . ") a été mis à jour avec succès.";
        header("Location: chef_projet.php"); // Redirection vers le tableau de bord
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur lors de la modification du besoin : " . $e->getMessage();
        header("Location: edit_besoin.php?id=" . urlencode($besoin_id));
        exit();
    }
} 

// --------------------------------------------------------
// --- B. AFFICHAGE DU FORMULAIRE DE MODIFICATION (READ)
// --------------------------------------------------------

// Récupérer l'ID du besoin à modifier
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Identifiant du besoin manquant pour la modification.";
    header("Location: chef_projet.php");
    exit();
}

$besoin_id = $_GET['id'];

try {
    // Récupérer les données du besoin pour pré-remplir le formulaire
    $stmt = $pdo->prepare("SELECT id, titre, description, statut, fichier FROM besoins WHERE id = :id AND utilisateur_id = :user_id");
    $stmt->execute([':id' => $besoin_id, ':user_id' => $utilisateur_id]);
    $besoin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$besoin) {
        $_SESSION['error'] = "Besoin introuvable ou vous n'êtes pas autorisé à le modifier.";
        header("Location: chef_projet.php");
        exit();
    }
    
    // Vérifier si la modification est autorisée
    if (!in_array($besoin['statut'], ['En attente de validation', 'En traitement'])) {
        $_SESSION['error'] = "La modification n'est plus possible car le besoin a le statut : " . htmlspecialchars($besoin['statut']) . ".";
        header("Location: chef_projet.php");
        exit();
    }

} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur lors de la récupération des données : " . $e->getMessage();
    header("Location: chef_projet.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le Besoin - <?= htmlspecialchars($besoin['id']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css"> 
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i> Modification du Besoin: <?= htmlspecialchars($besoin['titre']) ?></h4>
                        <small>ID: <?= htmlspecialchars($besoin['id']) ?> | Statut actuel: **<?= htmlspecialchars($besoin['statut']) ?>**</small>
                    </div>
                    <div class="card-body">
                        
                        <?php 
                        // Affichage des messages flash (succès ou erreur)
                        if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle me-2"></i>
                                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-x-octagon me-2"></i>
                                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form action="edit_besoin.php" method="POST" enctype="multipart/form-data">
                            
                            <input type="hidden" name="besoinId" value="<?= htmlspecialchars($besoin['id']) ?>">
                            <input type="hidden" name="fichierActuel" value="<?= htmlspecialchars($besoin['fichier']) ?>">

                            <div class="mb-3">
                                <label for="besoinTitre" class="form-label">Titre du besoin <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="besoinTitre" name="besoinTitre" value="<?= htmlspecialchars($besoin['titre']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="besoinDescription" class="form-label">Cadre(projet) <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="besoinDescription" name="besoinDescription" rows="5" required><?= htmlspecialchars($besoin['description']) ?></textarea>
                            </div>
                            
                            <div class="mb-3 p-3 border rounded">
                                <label class="form-label fw-bold">Fichier Joint (Cahier des charges ou TDR)</label>
                                
                                <?php if (!empty($besoin['fichier']) && $besoin['fichier'] !== 'null'): ?>
                                    <p class="mb-2">Fichier actuel : 
                                        <a href="./uploads/<?= htmlspecialchars($besoin['fichier']) ?>" target="_blank" class="text-primary fw-medium">
                                            <?= htmlspecialchars($besoin['fichier']) ?>
                                        </a>
                                    </p>
                                <?php else: ?>
                                    <p class="mb-2 text-muted">Aucun fichier n'est actuellement associé à ce besoin.</p>
                                <?php endif; ?>
                                
                                <label for="besoinNouveauFichier" class="form-label">Remplacer le fichier (Optionnel)</label>
                                <input class="form-control" type="file" id="besoinNouveauFichier" name="besoinNouveauFichier" accept=".pdf, .doc, .docx">
                                <div class="form-text">Si vous téléchargez un nouveau fichier, l'ancien sera supprimé. Formats autorisés : PDF, DOC, DOCX.</div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <a href="chef_projet.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i> Annuler et Retour</a>
                                <button type="submit" name="update_besoin" class="btn btn-success"><i class="bi bi-save me-2"></i> Enregistrer les modifications</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>