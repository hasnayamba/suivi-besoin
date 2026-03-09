
<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$demande_id = $_GET['id'] ?? $_POST['demande_id'] ?? null;
if (!$demande_id) {
    header('Location: demande_proforma.php');
    exit();
}

// --- GESTION DE LA MISE À JOUR (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $delai_reponse = $_POST['delai_reponse'];
    $besoin_id_nouveau = $_POST['besoin_id'];
    $fichier_actuel = $_POST['fichier_actuel']; 

    $nouveau_fichier_nom = $fichier_actuel;

    if (isset($_FILES['nouveau_fichier']) && $_FILES['nouveau_fichier']['error'] === UPLOAD_ERR_OK) {
        $uploadFileDir = __DIR__ . '/uploads/';

        if (!empty($fichier_actuel) && file_exists($uploadFileDir . $fichier_actuel)) {
            unlink($uploadFileDir . $fichier_actuel);
        }

        $fileTmpPath = $_FILES['nouveau_fichier']['tmp_name'];
        $fileName = basename($_FILES['nouveau_fichier']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $newFileName = 'DP_FILE_' . time() . '.' . $fileExtension;
        $destPath = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $nouveau_fichier_nom = $newFileName;
        } else {
            $_SESSION['error'] = "Erreur lors de la sauvegarde du nouveau fichier.";
            header('Location: modifier_demande.php?id=' . $demande_id);
            exit();
        }
    }

    try {
        $stmtTitre = $pdo->prepare("SELECT titre FROM besoins WHERE id = ?");
        $stmtTitre->execute([$besoin_id_nouveau]);
        $titre_besoin_nouveau = $stmtTitre->fetchColumn();

        $sql = "UPDATE demandes_proforma SET delai_reponse = ?, besoin_id = ?, titre_besoin = ?, fichier = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$delai_reponse, $besoin_id_nouveau, $titre_besoin_nouveau, $nouveau_fichier_nom, $demande_id]);

        $_SESSION['success'] = "La demande a été modifiée avec succès.";
        header('Location: demande_proforma.php');
        exit();

    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur lors de la modification : " . $e->getMessage();
    }
}


// --- RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE (GET) ---
$stmt = $pdo->prepare("SELECT * FROM demandes_proforma WHERE id = ?");
$stmt->execute([$demande_id]);
$demande = $stmt->fetch();

if (!$demande || !in_array($demande['statut'], ['En attente', 'Réponses en cours'])) {
    $_SESSION['error'] = "Cette demande ne peut plus être modifiée.";
    header('Location: demande_proforma.php');
    exit();
}

// CORRECTION ICI : Requête SQL améliorée pour la liste déroulante
$stmt_besoins = $pdo->prepare("SELECT id, titre FROM besoins WHERE statut = 'Validé' OR id = ? ORDER BY titre");
$stmt_besoins->execute([$demande['besoin_id']]);
$besoins_disponibles = $stmt_besoins->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier la Demande Proforma</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <?php
    include 'header.php';
    ?>
        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Modifier la Demande Proforma</h2>
                        <p class="text-muted mb-0 small">ID: <code><?= htmlspecialchars($demande['id']) ?></code></p>
                    </div>
                </div>
            </header>
            <main class="flex-fill overflow-auto p-4">
                <div class="card">
                    <div class="card-body">
                        <form action="modifier_demande.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="demande_id" value="<?= htmlspecialchars($demande['id']) ?>">
                            <input type="hidden" name="fichier_actuel" value="<?= htmlspecialchars($demande['fichier'] ?? '') ?>">

                            <div class="mb-3">
                                <label for="besoin_id" class="form-label">Besoin Associé</label>
                                <select class="form-select" id="besoin_id" name="besoin_id" required>
                                    <option value="">-- Sélectionnez un besoin --</option>
                                    <?php foreach ($besoins_disponibles as $besoin): ?>
                                        <option value="<?= htmlspecialchars($besoin['id']) ?>" <?= ($besoin['id'] === $demande['besoin_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($besoin['titre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="delai_reponse" class="form-label">Délai de réponse</label>
                                <input type="date" class="form-control" id="delai_reponse" name="delai_reponse" value="<?= htmlspecialchars($demande['delai_reponse'] ?? '') ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="nouveau_fichier" class="form-label">Changer le fichier joint</label>
                                <?php if (!empty($demande['fichier'])): ?>
                                    <p class="form-text">
                                        Fichier actuel: 
                                        <a href="uploads/<?= htmlspecialchars($demande['fichier'] ?? '') ?>" target="_blank"><?= htmlspecialchars($demande['fichier'] ?? '') ?></a>
                                    </p>
                                <?php endif; ?>
                                <input class="form-control" type="file" id="nouveau_fichier" name="nouveau_fichier">
                                <div class="form-text">Laissez vide pour conserver le fichier actuel.</div>
                            </div>

                            <div class="mt-4">
                                <a href="demande_proforma.php" class="btn btn-secondary">Annuler</a>
                                <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>