<?php

include 'db_connect.php';

$nom = "Administrateur";
$email = "admin@swisscontact.org";
$motDePasse = password_hash("123", PASSWORD_DEFAULT);
$role = "administration";

$sql = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role)
        VALUES (:nom, :email, :mot_de_passe, :role)";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':nom' => $nom,
    ':email' => $email,
    ':mot_de_passe' => $motDePasse,
    ':role' => $role
]);

echo "Administrateur créé avec succès !";