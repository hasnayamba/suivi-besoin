<?php
session_start();
require __DIR__ . '/db_connect.php';

// Vérification de sécurité
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Non connecté']);
    exit();
}

try {
    $utilisateur_id = $_SESSION['user_id'];
    
    // C'EST ICI QUE TOUT SE JOUE :
    // On marque comme lu (lue=1) ET on enregistre l'heure actuelle (NOW())
    // Seulement pour les notifs qui n'étaient pas encore lues
    $stmt = $pdo->prepare("UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE utilisateur_id = ? AND lue = 0");
    $stmt->execute([$utilisateur_id]);

    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>