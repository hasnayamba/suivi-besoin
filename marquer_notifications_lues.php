<?php
// On démarre la session au tout début
session_start();

// On utilise require et __DIR__ pour un chemin d'accès plus sûr
// __DIR__ représente le dossier où se trouve le fichier actuel (marquer_notifications_lues.php)
require __DIR__ . '/db_connect.php';

// On s'assure qu'un utilisateur est bien connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); // Accès interdit
    echo json_encode(['status' => 'error', 'message' => 'Utilisateur non connecté.']);
    exit();
}

try {
    $utilisateur_id = $_SESSION['user_id'];
    
    // La requête UPDATE pour marquer les notifications comme lues
    $stmt = $pdo->prepare("UPDATE notifications SET lue = 1 WHERE utilisateur_id = ? AND lue = 0");
    $stmt->execute([$utilisateur_id]);

    // On renvoie une réponse de succès au JavaScript
    http_response_code(200);
    header('Content-Type: application/json'); // On précise que la réponse est du JSON
    echo json_encode(['status' => 'success']);

} catch (PDOException $e) {
    // En cas d'erreur de base de données, on renvoie une erreur 500
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>