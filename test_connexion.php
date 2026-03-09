<?php
include 'db_connect.php';

$stmt = $pdo->query("SELECT NOW()");
echo "Connexion OK : ";
print_r($stmt->fetch());
?>