<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$besoin_id = $_GET['besoin_id'] ?? $_POST['besoin_id'] ?? null;
$ao_id = $_GET['ao_id'] ?? $_POST['ao_id'] ?? null;

if (!$besoin_id || !$ao_id) {
    $_SESSION['error'] = "Informations manquantes pour le dépouillement.";
    header('Location: suivi_ao.php');
    exit();
}

// --- TRAITEMENT DU FORMULAIRE DE DÉPOUILLEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_depouillement'])) {
    $fournisseur = trim($_POST['fournisseur']);
    $montant = !empty($_POST['montant']) ? trim($_POST['montant']) : null;

    if (empty($fournisseur) || !isset($_FILES['pv']) || $_FILES['pv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Le fournisseur et le PV sont obligatoires.";
        header('Location: depouillement_ao.php?ao_id=' . $ao_id . '&besoin_id=' . $besoin_id);
        exit();
    }
    
    // Fonction d'aide pour gérer l'upload (simplifiée pour le PV)
    function handle_upload($file, $marche_id, $doc_type, $pdo) {
        $fileExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $newFileName = strtoupper(str_replace(' ', '_', $doc_type)) . '_' . $marche_id . '_' . time() . '.' . $fileExtension;
        $destPath = __DIR__ . '/uploads/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $doc_id = 'DOC_' . time() . rand(100, 999);
            $sql = "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint) VALUES (?, ?, ?, CURDATE(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$doc_id, $marche_id, $doc_type, $newFileName]);
            return true;
        }
        return false;
    }
    
    $pdo->beginTransaction();
    try {
        $titre_besoin = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
        
        // 1. Créer le marché avec le nouveau statut "Attribué"
        $marche_id = 'M' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
        $sql_marche = "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id, type_procedure) 
                       VALUES (?, ?, ?, ?, CURDATE(), 'Attribué', ?, 'Appel d\'Offre')";
        $stmt_marche = $pdo->prepare($sql_marche);
        $stmt_marche->execute([$marche_id, $titre_besoin, $fournisseur, $montant, $besoin_id]);

        // 2. Mettre à jour le statut du besoin et de l'AO
        $pdo->prepare("UPDATE besoins SET statut = 'Marché attribué' WHERE id = ?")->execute([$besoin_id]);
        $pdo->prepare("UPDATE appels_offre SET statut = 'Attribué' WHERE id = ?")->execute([$ao_id]);

        // 3. Enregistrer le PV
        handle_upload($_FILES['pv'], $marche_id, 'PV', $pdo);
        
        // 4. Notifier le demandeur
        $id_demandeur = $pdo->query("SELECT utilisateur_id FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
        if ($id_demandeur) {
             $message_demandeur = "Votre besoin '" . htmlspecialchars($titre_besoin) . "' a été attribué suite à un appel d'offres.";
             $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, 'chef_projet.php')")->execute([$id_demandeur, $message_demandeur]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Le marché a été attribué avec succès. Veuillez maintenant choisir le type de contrat.";
        
        // REDIRECTION vers l'étape suivante : la contractualisation
        header('Location: contractualisation.php?marche_id=' . $marche_id);
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur : " . $e->getMessage();
    }
    header('Location: depouillement_ao.php?ao_id=' . $ao_id . '&besoin_id=' . $besoin_id);
    exit();
}

// --- AFFICHAGE DU FORMULAIRE (GET) ---
$besoin_titre = $pdo->query("SELECT titre FROM besoins WHERE id = " . $pdo->quote($besoin_id))->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dépouillement Appel d'Offres</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>
    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Dépouillement d'Appel d'Offres</h2>
                    <p class="text-muted mb-0 small">Besoin: <?= htmlspecialchars($besoin_titre) ?></p>
                </div>
                <a href="suivi_ao.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
             <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="besoin_id" value="<?= htmlspecialchars($besoin_id) ?>">
                <input type="hidden" name="ao_id" value="<?= htmlspecialchars($ao_id) ?>">

                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0">Informations sur le Gagnant</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="fournisseur" class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="fournisseur" name="fournisseur" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="montant" class="form-label">Montant Final (Optionnel)</label>
                                <input type="number" class="form-control" id="montant" name="montant" placeholder="Montant final attribué">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="pv" class="form-label">Procès-Verbal (PV) de dépouillement <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="pv" name="pv" accept=".pdf,.doc,.docx" required>
                            <div class="form-text">Ce document est obligatoire pour valider le choix.</div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" name="submit_depouillement" class="btn btn-success btn-lg">
                        <i class="bi bi-check2-circle me-2"></i> Valider le gagnant et continuer
                    </button>
                </div>
            </form>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>