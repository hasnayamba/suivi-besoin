<?php
session_start();
include 'db_connect.php'; // Inclure la connexion PDO

// Vérifier si l'ID du besoin à supprimer est présent
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Identifiant du besoin manquant pour la suppression.";
    header("Location: chef_projet.php");
    exit();
}

$besoin_id = $_GET['id'];
$utilisateur_id = 1; // Utiliser l'ID de l'utilisateur connecté (comme défini dans chef_projet.php)

try {
    // 1. Récupérer les informations sur le besoin pour vérifier le statut et le fichier
    $stmt = $pdo->prepare("SELECT statut, fichier FROM besoins WHERE id = :id AND utilisateur_id = :user_id");
    $stmt->execute([':id' => $besoin_id, ':user_id' => $utilisateur_id]);
    $besoin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$besoin) {
        $_SESSION['error'] = "Besoin introuvable ou vous n'êtes pas autorisé à le supprimer.";
        header("Location: chef_projet.php");
        exit();
    }

    // 2. Vérifier si la suppression est autorisée (par exemple, uniquement si "En attente de validation")
    if ($besoin['statut'] !== 'En attente de validation') {
        $_SESSION['error'] = "Impossible de supprimer un besoin dont le statut est : " . htmlspecialchars($besoin['statut']) . ".";
        header("Location: chef_projet.php");
        exit();
    }

    // 3. Si un fichier est associé, le supprimer du serveur
    if (!empty($besoin['fichier']) && $besoin['fichier'] !== 'null') {
        $filePath = './uploads/' . $besoin['fichier'];
        if (file_exists($filePath)) {
            unlink($filePath); // Supprime physiquement le fichier
        }
    }

    // 4. Supprimer l'enregistrement de la base de données
    $stmt = $pdo->prepare("DELETE FROM besoins WHERE id = :id");
    $stmt->execute([':id' => $besoin_id]);

    $_SESSION['success'] = "Le besoin " . htmlspecialchars($besoin_id) . " a été supprimé avec succès.";
    header("Location: chef_projet.php");
    exit();

} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur SQL lors de la suppression du besoin : " . $e->getMessage();
    header("Location: chef_projet.php");
    exit();
}
?>