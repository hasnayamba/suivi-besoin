<?php
session_start();
include 'db_connect.php';

// --- 1. REDIRECTION SI DÉJÀ CONNECTÉ ---
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    
    switch ($role) {
        case 'admin':
            header('Location: admin_dashboard.php');
            exit();
        case 'logisticien':
            header('Location: accueil_logisticien.php');
            exit();
        case 'comptable':
            header('Location: comptable_dashboard.php');
            exit();
        case 'finance':
            header('Location: finance_dashboard.php');
            exit();
        default:
            // Par défaut, c'est un demandeur (chef de projet)
            header('Location: chef_projet.php');
            exit();
    }
}

// --- 2. TRAITEMENT DU FORMULAIRE DE CONNEXION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $error = '';

    if (!empty($email) && !empty($password)) {
        try {
            $sql = "SELECT * FROM utilisateurs WHERE email = :email LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Vérification du mot de passe
            if ($user && password_verify($password, $user['mot_de_passe'])) {
                
                // Création de la session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Redirection après connexion réussie
                $role = strtolower($user['role']);
                
                if ($role === 'admin') {
                    header('Location: admin_dashboard.php');
                } elseif ($role === 'logisticien') {
                    header('Location: accueil_logisticien.php');
                } elseif ($role === 'comptable') {
                    header('Location: comptable_dashboard.php');
                } elseif ($role === 'finance') {
                    header('Location: finance_dashboard.php');
                } else {
                    header('Location: chef_projet.php');
                }
                exit();

            } else {
                $error = "Email ou mot de passe incorrect.";
            }
        } catch (PDOException $e) {
            $error = "Erreur de connexion à la base de données.";
        }
    } else {
        $error = "Veuillez remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        .card-header {
            background-color: #fff;
            border-bottom: none;
            padding: 25px 25px 10px;
            text-align: center;
        }
        .logo-img {
            max-height: 60px;
            margin-bottom: 15px;
        }
        .btn-login {
            background-color: #0d6efd;
            border: none;
            padding: 12px;
            font-weight: 500;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            background-color: #0b5ed7;
            transform: translateY(-1px);
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #0d6efd;
        }
        .input-group-text {
            background-color: #f8f9fa;
            border-right: none;
        }
        .form-control {
            border-left: none;
        }
        .input-group:focus-within .input-group-text {
            border-color: #0d6efd;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="card login-card mx-auto">
            <div class="card-header">
                <h3 class="text-primary fw-bold">SWISSCONTACT</h3>
                <p class="text-muted small">Portail de Gestion des Achats</p>
            </div>
            
            <div class="card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 text-center" role="alert">
                        <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">ADRESSE EMAIL</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" class="form-control" name="email" 
                                   placeholder="exemple@swisscontact.org" required autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">MOT DE PASSE</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" class="form-control" name="password" 
                                   placeholder="Votre mot de passe" required>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-login shadow-sm" id="loginButton">
                            <i class="fas fa-sign-in-alt me-2"></i>
                            Se connecter
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-white text-center py-3 border-0">
                <small class="text-muted">© <?= date('Y') ?> Swisscontact Niger</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>