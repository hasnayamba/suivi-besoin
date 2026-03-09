<?php
// Démarrer la session
session_start();

/*
Si l'utilisateur est déjà connecté,
on le redirige directement vers le dashboard
*/
if(isset($_SESSION['user_id'])){
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil - Swisscontact</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

<style>

:root {
    --swiss-blue: #0056b3;
    --swiss-blue-light: #007bff;
    --swiss-gray-dark: #495057;
    --swiss-gray: #6c757d;
    --swiss-gray-light: #e9ecef;
    --swiss-white: #ffffff;
}

body, html {
    height: 100%;
    margin: 0;
    padding: 0;
    background: linear-gradient(135deg, var(--swiss-gray-light) 0%, var(--swiss-white) 50%, var(--swiss-gray-light) 100%);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
}

.welcome-container{
    width:100%;
    max-width:600px;
    padding:20px;
}

.welcome-card{
    background:var(--swiss-white);
    border:1px solid var(--swiss-gray-light);
    border-radius:12px;
    box-shadow:0 15px 35px rgba(0,86,179,0.1);
}

.card-header{
    background:linear-gradient(135deg,var(--swiss-blue),var(--swiss-blue-light));
    color:white;
    padding:2rem;
    text-align:center;
}

.swiss-logo{
    font-weight:700;
    font-size:1.8rem;
}

.feature-badge{
    background:var(--swiss-gray-light);
    padding:6px 12px;
    border-radius:20px;
    margin:3px;
    font-size:0.85rem;
}

.btn-swiss{
    background:var(--swiss-blue);
    border:none;
    padding:12px;
    border-radius:8px;
    font-weight:600;
    color:white;
}

.btn-swiss:hover{
    background:var(--swiss-blue-light);
}

.security-badge{
    color:var(--swiss-gray);
}

</style>
</head>

<body>

<div class="welcome-container">
    <div class="card welcome-card">

        <div class="card-header">

            <div class="swiss-logo">SWISSCONTACT</div>
            <p class="mb-0 mt-2">Plateforme de Gestion</p>

        </div>

        <div class="card-body text-center p-4">

            <div class="mb-4">

                <span class="feature-badge">📊 Suivi des Marchés</span>
                <span class="feature-badge">📝 Gestion des Contrats</span>
                <span class="feature-badge">🤝 Conventions</span>

            </div>

            <p class="text-muted mb-4">

                Solution intégrée de suivi et gestion de vos marchés,
                contrats et conventions dans un environnement sécurisé
                et professionnel.

            </p>

            <div class="d-grid">

                <a href="login.php" class="btn btn-swiss">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Accéder à la plateforme
                </a>

            </div>

            <div class="mt-4 security-badge">

                <i class="bi bi-shield-check"></i>
                Environnement sécurisé

            </div>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>