<?php

include 'db_connect.php';

try {
    $stmt = $pdo->query("SELECT VERSION() AS version");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h2>Connexion OK</h2>";
    echo "<pre>";
    print_r($row);
    echo "</pre>";

} catch (Exception $e) {
    die($e->getMessage());
}