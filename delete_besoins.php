<?php
session_start();
include 'db_connect.php';

// Sécurité : vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$besoin_id = $_GET['id'] ?? null;
$utilisateur_id = $_SESSION['user_id'];

if ($besoin_id) {
    try {
        // Avant de supprimer, on récupère les infos pour supprimer le fichier physique
        $stmt = $pdo->prepare("SELECT fichier FROM besoins WHERE id = ? AND utilisateur_id = ? AND statut = 'En attente de validation'");
        $stmt->execute([$besoin_id, $utilisateur_id]);
        $besoin = $stmt->fetch();

        if ($besoin) {
            // Supprimer l'enregistrement de la base de données
            $delete_stmt = $pdo->prepare("DELETE FROM besoins WHERE id = ?");
            $delete_stmt->execute([$besoin_id]);

            // Si un fichier était associé, on le supprime aussi du serveur
            if (!empty($besoin['fichier'])) {
                $filePath = __DIR__ . '/uploads/' . $besoin['fichier'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            $_SESSION['success'] = "Le besoin a été annulé avec succès.";
        } else {
            $_SESSION['error'] = "Impossible d'annuler ce besoin (il a peut-être déjà été traité).";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur lors de l'annulation.";
    }
}

header('Location: chef_projet.php');
exit();
?>