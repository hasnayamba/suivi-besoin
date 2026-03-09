<?php
// 1. Démarrer la session
// Il est indispensable de démarrer la session pour pouvoir la manipuler et la détruire.
session_start();

// 2. Vider le tableau de session
// Supprime toutes les variables de la session (comme user_id, user_nom, role, etc.).
$_SESSION = array();

// 3. Détruire la session
// Cette fonction supprime la session côté serveur.
session_destroy();

// 4. Rediriger vers la page de connexion
// Une fois la session détruite, l'utilisateur est renvoyé vers la page de login.
header("Location: login.php");

// 5. Stopper l'exécution du script
// Il est important de s'assurer qu'aucun autre code n'est exécuté après la redirection.
exit();
?>