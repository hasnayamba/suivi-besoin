<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET VALIDATION ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// On récupère maintenant les données via POST depuis la modale
$reponse_id = $_POST['reponse_id'] ?? null;
$demande_id = $_POST['demande_id'] ?? null;

if (!$reponse_id || !$demande_id || !isset($_FILES['fichier_pv']) || $_FILES['fichier_pv']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "Informations manquantes ou erreur de fichier pour la validation.";
    // Rediriger vers la page précédente si possible, sinon vers la liste principale
    $redirect_url = $demande_id ? "gerer_reponses.php?id=$demande_id" : "demande_proforma.php";
    header("Location: $redirect_url");
    exit();
}


// --- GESTION DU FICHIER PV ---
$fichier_pv_nom = null;
$fileTmpPath = $_FILES['fichier_pv']['tmp_name'];
$fileName = basename($_FILES['fichier_pv']['name']);
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$uploadFileDir = __DIR__ . '/uploads/';
if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);

$newFileName = 'PV_' . time() . '.' . $fileExtension;
$destPath = $uploadFileDir . $newFileName;

if (!move_uploaded_file($fileTmpPath, $destPath)) {
    $_SESSION['error'] = "Erreur lors de la sauvegarde du fichier PV.";
    header('Location: gerer_reponses.php?id=' . $demande_id);
    exit();
}
$fichier_pv_nom = $newFileName;


// --- LOGIQUE DE TRAITEMENT AVEC TRANSACTION ---
$pdo->beginTransaction();

try {
    // 1. Récupérer les informations de l'offre gagnante et de la demande parente
    $sql_info = "SELECT pr.*, dp.titre_besoin, dp.besoin_id 
                 FROM proformas_recus pr
                 JOIN demandes_proforma dp ON pr.demande_proforma_id = dp.id
                 WHERE pr.id = :reponse_id AND pr.demande_proforma_id = :demande_id";
    $stmt_info = $pdo->prepare($sql_info);
    $stmt_info->execute([':reponse_id' => $reponse_id, ':demande_id' => $demande_id]);
    $info = $stmt_info->fetch();

    if (!$info) {
        throw new Exception("Impossible de trouver l'offre ou la demande correspondante.");
    }

    // 2. Mettre à jour l'offre gagnante à 'Validé'
    $stmt_valider = $pdo->prepare("UPDATE proformas_recus SET statut = 'Validé' WHERE id = :reponse_id");
    $stmt_valider->execute([':reponse_id' => $reponse_id]);

    // 3. Mettre à jour les autres offres à 'Rejeté'
    $stmt_rejeter = $pdo->prepare("UPDATE proformas_recus SET statut = 'Rejeté' WHERE demande_proforma_id = :demande_id AND id != :reponse_id");
    $stmt_rejeter->execute([':demande_id' => $demande_id, ':reponse_id' => $reponse_id]);

    // 4. Mettre à jour la demande de proforma à 'Validé'
    $stmt_demande = $pdo->prepare("UPDATE demandes_proforma SET statut = 'Validé' WHERE id = :demande_id");
    $stmt_demande->execute([':demande_id' => $demande_id]);

    // 5. Créer le nouveau marché
    $marche_id = 'M' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
    $stmt_marche = $pdo->prepare(
        "INSERT INTO marches (id, titre, fournisseur, montant, date_debut, statut, besoin_id) 
         VALUES (:id, :titre, :fournisseur, :montant, CURDATE(), 'En cours', :besoin_id)"
    );
    $stmt_marche->execute([
        ':id' => $marche_id,
        ':titre' => $info['titre_besoin'],
        ':fournisseur' => $info['fournisseur'],
        ':montant' => $info['montant'],
        ':besoin_id' => $info['besoin_id']
    ]);
    
    // 6. Enregistrer le document PV dans la nouvelle table
    $doc_id = 'DOC_' . time();
    $stmt_pv = $pdo->prepare(
        "INSERT INTO documents_commande (id, marche_id, type_document, date_document, fichier_joint)
         VALUES (:id, :marche_id, 'PV', CURDATE(), :fichier)"
    );
    $stmt_pv->execute([
        ':id' => $doc_id,
        ':marche_id' => $marche_id,
        ':fichier' => $fichier_pv_nom
    ]);

    // 7. Mettre à jour le besoin initial à 'Marché attribué'
    $stmt_besoin = $pdo->prepare("UPDATE besoins SET statut = 'Marché attribué' WHERE id = :besoin_id");
    $stmt_besoin->execute([':besoin_id' => $info['besoin_id']]);


    // Si tout s'est bien passé, on valide la transaction
    $pdo->commit();
    $_SESSION['success'] = "L'offre de " . htmlspecialchars($info['fournisseur']) . " a été validée et le marché a été créé avec succès.";

} catch (Exception $e) {
    // En cas d'erreur, on annule tout
    $pdo->rollBack();
    $_SESSION['error'] = "Une erreur est survenue : " . $e->getMessage();
}

// Rediriger l'utilisateur vers la page de gestion des réponses
header('Location: gerer_reponses.php?id=' . $demande_id);
exit();
?>