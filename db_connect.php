<?php

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

echo "<pre>";
echo "HOST : "; var_dump($dbHost);
echo "DB   : "; var_dump($dbName);
echo "USER : "; var_dump($dbUser);
echo "PASS : "; var_dump($dbPass);
echo "</pre>";

$dsn = "mysql:host={$dbHost};port=3306;dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,

    PDO::MYSQL_ATTR_SSL_CA => "/etc/ssl/certs/ca-certificates.crt",
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    echo "<h2 style='color:green'>Connexion OK</h2>";
} catch (PDOException $e) {
    echo "<h2 style='color:red'>Erreur PDO :</h2>";
    echo "<pre>";
    print_r($e->errorInfo);
    echo "</pre>";
    die($e->getMessage());
}