<?php

$host = getenv("DB_HOST");
$user = getenv("DB_USER");
$pass = getenv("DB_PASS");
$db   = getenv("DB_NAME");

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
];

try {

    $pdo = new PDO(
        "mysql:host=$host;port=3306;dbname=$db;charset=utf8mb4;sslmode=require",
        $user,
        $pass,
        $options
    );

} catch (PDOException $e) {

    die("Erreur de connexion : " . $e->getMessage());

}

?>