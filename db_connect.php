<?php
die("JE PASSE BIEN PAR DB_CONNECT.PHP");

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$dsn = "mysql:host={$dbHost};port=3306;dbname={$dbName};charset=utf8mb4";

echo "<pre>";
var_dump($dbHost);
var_dump($dbName);
var_dump($dbUser);
echo "</pre>";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,

    PDO::MYSQL_ATTR_SSL_CA => "/etc/ssl/certs/ca-certificates.crt",
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    echo "Connexion OK";
} catch (PDOException $e) {
    die($e->getMessage());
}