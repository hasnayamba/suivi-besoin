<?php

define('DB_HOST', 'suivi-besoins-db.mysql.database.azure.com');
define('DB_USER', 'adminuser@suivi-besoins-db');
define('DB_PASS', '94649092@Hy');
define('DB_NAME', 'besoin');

try {

    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::MYSQL_ATTR_SSL_CA => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (PDOException $e) {

    die("Erreur de connexion : " . $e->getMessage());

}

?>