<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Réservé à l'administrateur ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: login.php');
    exit();
}

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Administrateur';

// --- STATISTIQUES RAPIDES ---
try {
    $count_users = $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
    $count_fournisseurs = $pdo->query("SELECT COUNT(*) FROM fournisseurs")->fetchColumn();
    $count_projets = $pdo->query("SELECT COUNT(*) FROM projets")->fetchColumn();
} catch (PDOException $e) {
    $count_users = $count_fournisseurs = $count_projets = 0;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .sidebar { min-width: 260px; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 5px rgba(31, 109, 255, 0.05); }
        .sidebar .p-4 { background-color: #212529; color: white; }
        .sidebar .nav-link { color: #495057; border-radius: 8px; padding: 10px 15px; margin-bottom: 5px; font-weight: 500; }
        .sidebar .nav-link:hover { background-color: #e9ecef; color: #212529; }
        .sidebar .nav-link.active { background-color: #0d6efd; color: white; }
        .admin-card { transition: transform 0.2s, box-shadow 0.2s; border: none; border-radius: 12px; }
        .admin-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
        .icon-box { width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.8rem; }
    </style>
</head>
<body>

<div class="d-flex vh-100">
    <div class="collapse d-md-block" id="sidebarCollapse">
        <nav class="sidebar bg-white border-end">
            <div class="p-4 border-bottom">
                <h5 class="mb-1"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>Swisscontact</h5>
                <small class="opacity-75">Portail Admin</small>
            </div>
            <div class="p-3">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="admin.php"><i class="bi bi-speedometer2 me-3"></i>Tableau de bord</a>
                    </li>
                    <li class="nav-item mt-3 mb-1 text-muted small text-uppercase fw-bold px-3">Gestion</li>
                    <li class="nav-item">
                        <a class="nav-link" href="utilisateurs.php"><i class="bi bi-people me-3"></i>Utilisateurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="fournisseurs.php"><i class="bi bi-truck me-3"></i>Fournisseurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="projets.php"><i class="bi bi-folder me-3"></i>Projets / Fonds</a>
                    </li>
                </ul>
            </div>
            <div class="position-absolute bottom-0 w-100 p-3 border-top">
                <a href="deconnexion.php" class="btn btn-outline-danger w-100"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a>
            </div>
        </nav>
    </div>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h3 class="h5 mb-0 fw-bold">Espace Administrateur</h3>
                <small class="text-muted">Bienvenue, <?= htmlspecialchars($utilisateur_nom) ?></small>
            </div>
            <div class="dropdown">
                <button class="btn btn-light rounded-circle bg-primary text-white border-0 shadow-sm" style="width:40px;height:40px" data-bs-toggle="dropdown">
                    <i class="bi bi-person-fill"></i>
                </button>
            </div>
        </header>

        <main class="p-4 p-md-5">
            <h4 class="mb-4 text-dark fw-bold">Vue d'ensemble du système</h4>

            <div class="row g-4">
                
                <div class="col-md-4">
                    <div class="card admin-card shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="icon-box bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                                <span class="badge bg-primary fs-6 rounded-pill px-3"><?= $count_users ?></span>
                            </div>
                            <h5 class="fw-bold mb-1">Comptes Utilisateurs</h5>
                            <p class="text-muted small mb-4">Gérez les accès, les rôles (Chef de projet, Finance, Logistique) et les <strong>antennes</strong>.</p>
                            <a href="utilisateurs.php" class="btn btn-primary w-100 fw-bold">Gérer les utilisateurs <i class="bi bi-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card admin-card shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="icon-box bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-shop"></i>
                                </div>
                                <span class="badge bg-success fs-6 rounded-pill px-3"><?= $count_fournisseurs ?></span>
                            </div>
                            <h5 class="fw-bold mb-1">Base Fournisseurs</h5>
                            <p class="text-muted small mb-4">Ajoutez ou modifiez les prestataires, leurs coordonnées et leur <strong>localisation</strong>.</p>
                            <a href="fournisseurs.php" class="btn btn-success w-100 fw-bold text-white">Gérer les fournisseurs <i class="bi bi-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card admin-card shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="icon-box bg-warning bg-opacity-10 text-warning">
                                    <i class="bi bi-folder-fill"></i>
                                </div>
                                <span class="badge bg-warning text-dark fs-6 rounded-pill px-3"><?= $count_projets ?></span>
                            </div>
                            <h5 class="fw-bold mb-1">Projets & Fonds</h5>
                            <p class="text-muted small mb-4">Configurez les sources de financement disponibles pour l'expression des besoins.</p>
                            <a href="projets.php" class="btn btn-warning w-100 fw-bold text-dark">Gérer les projets <i class="bi bi-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>