<?php
session_start();

// Si l'utilisateur est déjà connecté, on controle la rediricertion
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role'])) {
        // Redirection en fonction du rôle
        if (strtolower($_SESSION['role']) === 'logisticien') {
            header('Location: accueil_logisticien.php'); 
            exit;
        } elseif (strtolower($_SESSION['role']) === 'comptable') {
            header('Location: comptable_dashboard.php');
            exit;
        } elseif (strtolower($_SESSION['role']) === 'finance') {
            header('Location: finance_dashboard.php');
            exit;
        } else {
            header('Location: chef_projet.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'db_connect.php';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $error = '';

    if (!empty($email) && !empty($password)) {
        $sql = "SELECT * FROM utilisateurs WHERE email = :email LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "<pre>";
        var_dump($user);
        echo "</pre>";
        exit;

        if ($user && password_verify($password, $user['mot_de_passe'])) {
            // Stocker les informations de l'utilisateur dans la session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // MODIFICATION ICI : Redirection vers le nouveau portail
            if (strtolower($user['role']) === 'logisticien') {
                header('Location: accueil_logisticien.php');
                exit;
            } elseif (strtolower($user['role']) === 'comptable') {
                header('Location: comptable_dashboard.php');
                exit;
            } elseif (strtolower($user['role']) === 'finance') {
                header('Location: finance_dashboard.php');
                exit;
            } else {
                // Pour tous les autres rôles ('chef de projet', 'moi', etc.)
                header('Location: chef_projet.php');
                exit;
            }
        } else {
            $error = "Email ou mot de passe incorrect.";
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
    <title>Connexion - Swisscontact </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --swiss-blue: #0056b3;
            --swiss-blue-light: #007bff;
            --swiss-gray-dark: #495057;
            --swiss-gray: #6c757d;
            --swiss-gray-light: #e9ecef;
            --swiss-white: #ffffff;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, var(--swiss-gray-light) 0%, var(--swiss-white) 50%, var(--swiss-gray-light) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 440px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        .login-card {
            background: var(--swiss-white);
            border: 1px solid var(--swiss-gray-light);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 86, 179, 0.15);
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--swiss-blue) 0%, var(--swiss-blue-light) 100%);
            color: var(--swiss-white);
            padding: clamp(1.5rem, 4vw, 2rem);
            text-align: center;
            border-bottom: none;
        }
        
        .logo-icon {
            font-size: clamp(2rem, 6vw, 2.5rem);
            margin-bottom: 0.8rem;
            opacity: 0.95;
        }
        
        .brand-name {
            font-weight: 700;
            font-size: clamp(1.3rem, 4vw, 1.5rem);
            letter-spacing: -0.5px;
            margin-bottom: 0.3rem;
        }
        
        .app-name {
            font-size: clamp(0.9rem, 3vw, 1rem);
            opacity: 0.9;
            font-weight: 500;
        }
        
        .login-body {
            padding: clamp(1.5rem, 4vw, 2.5rem);
            background: var(--swiss-white);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--swiss-gray-dark);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .input-group {
            margin-bottom: 1.2rem;
        }
        
        .input-group-text {
            background: var(--swiss-gray-light);
            border: 1px solid #dee2e6;
            border-right: none;
            color: var(--swiss-gray);
            min-width: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .form-control {
            border: 1px solid #dee2e6;
            border-left: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--swiss-blue-light);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.1);
        }
        
        .form-control:focus + .input-group-text {
            border-color: var(--swiss-blue-light);
        }
        
        .btn-login {
            background: var(--swiss-blue);
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.05rem;
            transition: all 0.3s ease;
            color: white;
            width: 100%;
            margin-top: 0.5rem;
        }
        
        .btn-login:hover {
            background: var(--swiss-blue-light);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 86, 179, 0.3);
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 0.9rem 1rem;
            font-size: 0.95rem;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                padding: 15px;
                align-items: flex-start;
                padding-top: 40px;
            }
            
            .login-container {
                max-width: 100%;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
                padding-top: 20px;
            }
            
            .login-body {
                padding: 1.2rem;
            }
            
            .login-header {
                padding: 1.2rem;
            }
            
            .btn-login {
                padding: 0.8rem 1.5rem;
            }
        }
        
        @media (max-width: 360px) {
            .login-body {
                padding: 1rem;
            }
            
            .login-header {
                padding: 1rem;
            }
            
            .brand-name {
                font-size: 1.2rem;
            }
        }
        
        /* Empêcher le zoom sur iOS */
        @media (max-width: 768px) {
            input, select, textarea {
                font-size: 16px;
            }
        }
        
        /* Loading state */
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-building-check logo-icon"></i>
                <div class="brand-name">SWISSCONTACT</div>
                
            </div>
            <div class="login-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="login.php" id="loginForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">Adresse email</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="votre@email.com" required 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">Mot de passe</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Votre mot de passe" required>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-login" id="loginButton">
                            <i class="fas fa-sign-in-alt me-2"></i>
                            Se connecter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Ajout d'un effet de loading lors de la soumission
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = document.getElementById('loginButton');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Connexion...';
        });
        
        // Effet de focus amélioré
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focus');
            });
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focus');
            });
        });
    </script>
</body>
</html>