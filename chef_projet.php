<?php
session_start();
include 'db_connect.php';

// --- GESTION DE L'UTILISATEUR CONNECTÉ ET SÉCURITÉ ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
//  les rôles qui ne sont NI logisticien NI comptable NI finance ne peuvent voir cette page
if (in_array(strtolower($_SESSION['role']), ['comptable', 'logisticien', 'finance'])) {
    // Redirection vers un tableau de bord par défaut ou une page d'accès refusé
    header('Location: unauthorized.php'); 
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Utilisateur';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- LOGIQUE POUR LES NOTIFICATIONS ---
$notifications = [];
$unread_count = 0;
try {
    $stmt_notif = $pdo->prepare("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 5");
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de notification: " . $e->getMessage());
}

// --- LOGIQUE POUR LES COMPTEURS DE STATUT ---
$compteurs = [
    'envoyees' => 0,
    'en_cours' => 0,
    'approuvees' => 0,
    'rejetees' => 0
];

try {
    $sql_compteurs = "SELECT statut, COUNT(*) as total FROM besoins WHERE utilisateur_id = :utilisateur_id GROUP BY statut";
    $stmt_compteurs = $pdo->prepare($sql_compteurs);
    $stmt_compteurs->execute([':utilisateur_id' => $utilisateur_id]);
    $resultats_compteurs = $stmt_compteurs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultats_compteurs as $row) {
        $total = (int) $row['total'];
        $statut = $row['statut'];

        $compteurs['envoyees'] += $total; // Toutes les demandes comptent comme envoyées

        // Statuts "En Cours"
        if (in_array($statut, ['En attente de Finance', 'Correction Requise', 'En cours de proforma', 'En traitement', 'Marché attribué'])) {
            $compteurs['en_cours'] += $total;
        // Statuts "Approuvé"
        } elseif (in_array($statut, ['Validé', 'Paiement Approuvé'])) {
            $compteurs['approuvees'] += $total;
        // Statuts "Rejeté"
        } elseif (in_array($statut, ['Rejeté par Finance', 'Rejeté par Comptable', 'Rejeté'])) {
            $compteurs['rejetees'] += $total;
        }
    }
} catch (PDOException $e) {
    error_log("Erreur de compteur: " . $e->getMessage());
}


// Fonction pour générer un ID de besoin unique
function generateBesoinId() {
    return 'B' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
}

// --- GESTION DE LA SOUMISSION DU BESOIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_besoin'])) {
    $titre = trim($_POST['besoinTitre']);
    $description = trim($_POST['besoinDescription']);
    $montant = !empty($_POST['besoinMontant']) ? trim($_POST['besoinMontant']) : null; 
    $fichier_nom = null;

    if (empty($titre) || empty($description)) {
        $_SESSION['error'] = "Le titre et la description sont des champs obligatoires.";
    } else {
        // Logique de gestion du fichier (upload)
        if (isset($_FILES['besoinFichier']) && $_FILES['besoinFichier']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['besoinFichier']['tmp_name'];
            $fileName = basename($_FILES['besoinFichier']['name']);
            $fileSize = $_FILES['besoinFichier']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx'];
            $maxFileSize = 5 * 1024 * 1024; // 5 Mo maximum

            if ($fileSize > $maxFileSize) {
                $_SESSION['error'] = "Le fichier est trop volumineux (max 5 Mo).";
            } elseif (!in_array($fileExtension, $allowedExtensions)) {
                $_SESSION['error'] = "Extension non autorisée. Seuls PDF, DOC et DOCX sont acceptés.";
            } else {
                $uploadFileDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                
                $newFileName = generateBesoinId() . '.' . $fileExtension;
                $destPath = $uploadFileDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $fichier_nom = $newFileName;
                } else {
                    $_SESSION['error'] = "Erreur lors du déplacement du fichier téléchargé.";
                }
            }
        }

        // Insertion en base de données
        if (!isset($_SESSION['error'])) {
            $id = generateBesoinId();
            try {
                // Le statut par défaut est "En attente de Finance"
                $sql = "INSERT INTO besoins (id, titre, description, montant, date_soumission, statut, fichier, utilisateur_id) 
                         VALUES (:id, :titre, :description, :montant, CURDATE(), 'En attente de Finance', :fichier, :utilisateur_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id' => $id, 
                    ':titre' => $titre, 
                    ':description' => $description,
                    ':montant' => $montant, 
                    ':fichier' => $fichier_nom, 
                    ':utilisateur_id' => $utilisateur_id
                ]);
                
                // Notifier tous les agents du rôle 'finance'
                $agents_finance = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'finance'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($agents_finance as $finance_id) {
                    $message = "Nouveau besoin '" . htmlspecialchars($titre) . "' soumis par " . htmlspecialchars($utilisateur_nom);
                    $lien = "finance_view_besoin.php?id=$id"; // Lien vers la page de validation finance
                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                    $stmt_notif->execute([$finance_id, $message, $lien]);
                }
                $_SESSION['success'] = "Le besoin '$titre' a été soumis pour validation financière.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
            }
        }
    }
    header("Location: chef_projet.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Chef de Projet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        /* 1. Amélioration du Sidebar */
        .sidebar {
            transition: transform 0.3s ease-in-out;
            min-width: 260px;
            height: 100vh;
            position: sticky;
            top: 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05); 
        }

        /* Style spécifique pour le bandeau "Suivi des Besoins" (en bleu) */
        .sidebar .p-4.border-bottom {
            background-color: #0d6efd; /* Bleu de Bootstrap */
            color: white; 
            border-bottom: none !important; 
        }

        .sidebar .p-4.border-bottom h5 {
            color: white;
        }
        
        /* Conteneur des liens de navigation  */
        .sidebar > .p-3 { 
            background-color: white;
            flex-grow: 1;
        }

        /* Style spécifique pour les liens de navigation */
        .sidebar .nav-link {
            color: #495057;
            padding: 10px 15px;
            border-radius: 8px;
            transition: background-color 0.2s, color 0.2s;
        }

        .sidebar .nav-link:hover {
            background-color: #f8f9fa; 
            color: #0d6efd;
        }

        .sidebar .nav-link.active {
            background-color: #0d6efd; 
            color: white;
            font-weight: 600;
        }

        /* 2. Style de l'En-tête (Header) */
        header {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            background-color: white; 
        }
        header h2 {
            color: #343a40; 
        }

        /* 3. Cartes et Tableau */
        .card {
            border: none;
            border-radius: 12px;
            
        }
        
        #historique-besoins {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); 
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f1f1 !important;
        }

        /* 4. Amélioration de la Responsivité (Mobile) */
        @media (max-width: 768px) {
            .d-flex.vh-100 {
                flex-direction: column;
            }
            .collapse:not(.show) { 
                display: none !important;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 1px solid #dee2e6;
            }
            .sidebar .p-3 {
                padding-top: 0 !important;
            }
            .main-content {
                overflow-y: visible !important;
            }
            .d-md-none {
                display: block !important;
            }
            .header .text-muted.small {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-light">

    <div class="d-flex vh-100">
        
        <div class="collapse d-md-block" id="sidebarCollapse">
            <nav class="sidebar bg-white border-end">
                <div class="p-4 border-bottom">
                    <h5 class="mb-1">Swisscontact</h5>
                    <small class="opacity-75">Soumission de besoin</small>
                </div>
                <div class="p-3">
                    <ul class="nav nav-pills flex-column">
                        <li class="nav-item mb-1"><a class="nav-link active" href="chef_projet.php"><i class="bi bi-person-workspace me-2"></i>Tableau de Bord</a></li>
                    </ul>
                    <div class="mt-4">
                        <ul class="nav nav-pills flex-column">
                            <li class="nav-item mb-1"><a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#newBesoinModal"><i class="bi bi-file-earmark-plus me-2"></i>Exprimer un besoin</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    
                    <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse" aria-expanded="false" aria-controls="sidebarCollapse">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div>
                        <h2 class="mb-1">Tableau de Bord</h2>
                        <p class="text-muted mb-0 small">Soumission et suivi de vos besoins</p>
                    </div>
                    
                    <div class="d-flex align-items-center gap-3">
                        <div class="dropdown">
                            <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                                <i class="bi bi-bell"></i>
                                <?php if ($unread_count > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;" aria-labelledby="notifDropdown">
                                <li class="dropdown-header">Notifications</li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if (empty($notifications)): ?>
                                    <li class="px-3 py-2 text-muted small">Aucune notification</li>
                                <?php else: foreach ($notifications as $notif): ?>
                                    <li class="px-3 py-2 <?= $notif['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                        <a href="<?= htmlspecialchars($notif['lien'] ?? '#') ?>" class="text-decoration-none text-dark">
                                            <div class="small"><?= htmlspecialchars($notif['message']) ?></div>
                                            <div class="small text-muted fst-italic"><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?></div>
                                        </a>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle me-2"></i>
                                <span><?= htmlspecialchars($utilisateur_nom) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li class="px-3 py-2">
                                    <div class="fw-medium"><?= htmlspecialchars($utilisateur_nom) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="deconnexion.php">Déconnexion</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-fill overflow-auto p-4">
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <div class="row mb-4">
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-primary text-white" style="box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title fw-bold mb-0"><?= $compteurs['envoyees'] ?></h5>
                                    <p class="card-text small">Demandes Envoyées (Total)</p>
                                </div>
                                <i class="bi bi-send-check fs-2 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-warning text-dark" style="box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title fw-bold mb-0"><?= $compteurs['en_cours'] ?></h5>
                                    <p class="card-text small">En Cours de Traitement</p>
                                </div>
                                <i class="bi bi-hourglass-split fs-2 opacity-75"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-success text-white" style="box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title fw-bold mb-0"><?= $compteurs['approuvees'] ?></h5>
                                    <p class="card-text small">Demandes Approuvées</p>
                                </div>
                                <i class="bi bi-check-circle fs-2 opacity-75"></i>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-danger text-white" style="box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title fw-bold mb-0"><?= $compteurs['rejetees'] ?></h5>
                                    <p class="card-text small">Demandes Rejetées</p>
                                </div>
                                <i class="bi bi-x-octagon fs-2 opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end mb-4">
                    <button class="btn btn-primary d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#newBesoinModal"><i class="bi bi-file-earmark-plus me-2"></i>Soumettre un nouveau besoin</button>
                </div>
                
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-3 p-3 bg-white rounded-3 border">
                    <div class="mb-2 mb-md-0 d-flex align-items-center">
                        <label for="filtreStatut" class="form-label mb-0 me-2 small fw-bold">Filtrer par statut :</label>
                        <select class="form-select form-select-sm" id="filtreStatut">
                            <option value="">Tous les statuts</option>
                            <option value="En attente de Finance">En attente de Finance</option>
                            <option value="Correction Requise">Correction Requise</option>
                            <option value="En cours">En cours de traitement</option>
                            <option value="Validé">Approuvé</option>
                            <option value="Rejeté">Rejeté</option>
                        </select>
                    </div>
                    <div class="w-100 w-md-auto d-flex align-items-center">
                        <label for="searchTable" class="form-label mb-0 me-2 small fw-bold">Rechercher:</label>
                        <input type="text" id="searchTable" class="form-control form-control-sm" placeholder="Rechercher par titre ou ID...">
                    </div>
                </div>
                <div class="card" id="historique-besoins">
                    <div class="card-header"><h5 class="card-title mb-0">Historique de mes besoins</h5></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>ID</th><th>Titre</th><th>Date</th><th>Statut</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Requête SQL de récupération des besoins de l'utilisateur
                                    $sql = "SELECT b.id, b.titre, b.date_soumission, b.statut, b.description, b.fichier, b.montant,
                                                 b.motif_rejet AS motif_rejet_log_fin, 
                                                 m.motif_rejet AS motif_rejet_comptable 
                                            FROM besoins b 
                                            LEFT JOIN marches m ON b.id = m.besoin_id 
                                            WHERE b.utilisateur_id = :utilisateur_id 
                                            ORDER BY b.date_soumission DESC";
                                    $stmt = $pdo->prepare($sql);
                                    $stmt->execute([':utilisateur_id' => $utilisateur_id]);
                                    
                                    if ($stmt->rowCount() > 0) {
                                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $badge_class = 'bg-secondary';
                                            if (in_array($row['statut'], ['Validé', 'Marché attribué', 'Paiement Approuvé'])) $badge_class = 'bg-success';
                                            elseif (in_array($row['statut'], ['Rejeté', 'Rejeté par Comptable', 'Rejeté par Finance'])) $badge_class = 'bg-danger';
                                            elseif (in_array($row['statut'], ['En attente de validation', 'Correction Requise', 'En attente de Finance'])) $badge_class = 'bg-warning text-dark';
                                            else $badge_class = 'bg-primary';
                                            
                                            echo '<tr>';
                                            echo '<td><code>' . htmlspecialchars($row['id']) . '</code></td>';
                                            echo '<td>' . htmlspecialchars($row['titre']) . '</td>';
                                            echo '<td>' . date('d/m/Y', strtotime($row['date_soumission'])) . '</td>';
                                            echo '<td><span class="badge ' . $badge_class . '">' . htmlspecialchars($row['statut']) . '</span></td>';
                                            echo '<td class="text-end"><div class="dropdown">';
                                            echo '<button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>';
                                            echo '<ul class="dropdown-menu dropdown-menu-end">';
                                            echo '<li><a class="dropdown-item view-besoin-btn" href="#" 
                                                             data-bs-toggle="modal" data-bs-target="#viewBesoinModal"
                                                             data-id="' . htmlspecialchars($row['id']) . '"
                                                             data-titre="' . htmlspecialchars($row['titre']) . '"
                                                             data-description="' . htmlspecialchars($row['description'] ?? '') . '"
                                                             data-montant="' . htmlspecialchars($row['montant'] ?? 'N/A') . '"
                                                             data-statut="' . htmlspecialchars($row['statut']) . '"
                                                             data-date="' . htmlspecialchars(date('d/m/Y', strtotime($row['date_soumission']))) . '"
                                                             data-fichier="' . htmlspecialchars($row['fichier'] ?? '') . '"
                                                             data-motif-log-fin="' . htmlspecialchars($row['motif_rejet_log_fin'] ?? '') . '"
                                                             data-motif-comptable="' . htmlspecialchars($row['motif_rejet_comptable'] ?? '') . '">
                                                             <i class="bi bi-eye me-2"></i>Voir détails</a></li>';
                                            
                                            // L'utilisateur peut modifier si "En attente de Finance" OU "Correction Requise"
                                            if (in_array($row['statut'], ['En attente de Finance', 'Correction Requise'])) {
                                                echo '<li><a class="dropdown-item" href="modifier_besoin.php?id=' . htmlspecialchars($row['id']) . '"><i class="bi bi-pencil me-2"></i>Modifier</a></li>';
                                            }
                                            // L'utilisateur ne peut annuler que s'il n'a pas encore été traité par la finance
                                            if ($row['statut'] == 'En attente de Finance') {
                                                echo '<li><hr class="dropdown-divider"></li>';
                                                echo '<li><a class="dropdown-item text-danger" href="delete_besoin.php?id=' . htmlspecialchars($row['id']) . '" onclick="return confirm(\'Êtes-vous sûr ?\')"><i class="bi bi-trash me-2"></i>Annuler</a></li>';
                                            }
                                            echo '</ul></div></td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center text-muted">Aucun besoin soumis pour le moment.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

<div class="modal fade" id="newBesoinModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Soumettre un nouveau besoin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form action="chef_projet.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3"><label for="besoinTitre" class="form-label">Titre du besoin <span class="text-danger">*</span></label><input type="text" class="form-control" id="besoinTitre" name="besoinTitre" required></div>
                    <div class="mb-3">
                        <label for="besoinMontant" class="form-label">Montant Estimatif (cfa) </label>
                        <input type="number" class="form-control" id="besoinMontant" name="besoinMontant" placeholder="Ex: 500000">
                    </div>
                    <div class="mb-3"><label for="besoinDescription" class="form-label">Cadre(projet) <span class="text-danger">*</span></label><textarea class="form-control" id="besoinDescription" name="besoinDescription" rows="4" required></textarea></div>
                    <div class="mb-3"><label for="besoinFichier" class="form-label">Cahier des charges (Optionnel)</label><input class="form-control" type="file" id="besoinFichier" name="besoinFichier" accept=".pdf,.doc,.docx"><div class="form-text">Formats autorisés : PDF, DOC, DOCX. Max 5Mo.</div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="submit_besoin" class="btn btn-primary">Soumettre</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewBesoinModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="viewBesoinModalLabel">Détails du besoin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <dl class="row">
                    <dt class="col-sm-3">ID</dt><dd class="col-sm-9"><code id="detailId"></code></dd>
                    <dt class="col-sm-3">Titre</dt><dd class="col-sm-9" id="detailTitre"></dd>
                    <dt class="col-sm-3">Statut</dt><dd class="col-sm-9"><span class="badge" id="detailStatut"></span></dd>
                    <dt class="col-sm-3">Date de soumission</dt><dd class="col-sm-9" id="detailDate"></dd>
                    <dt class="col-sm-3">Montant Estimatif</dt><dd class="col-sm-9 fw-bold" id="detailMontant"></dd>
                </dl>
                <h6>Cadre(projet)</h6><p id="detailDescription"></p>
                <h6>Fichier joint</h6>
                <div id="detailFichierWrapper"><p id="detailFichierText"></p><a id="detailTelechargerLien" href="#" class="btn btn-sm btn-outline-primary d-none" download><i class="bi bi-download me-2"></i>Télécharger</a></div>

                <div id="motifRejetFinanceSection" class="alert alert-danger d-none mt-3">
                    <h6 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Rejeté par la Finance</h6>
                    <p><strong>Motif du rejet :</strong></p>
                    <p id="detailMotifFinance" class="mb-0"></p>
                </div>
                <div id="motifRejetLogisticienSection" class="alert alert-warning d-none mt-3">
                    <h6 class="alert-heading"><i class="bi bi-pencil-square"></i> Correction Requise</h6>
                    <p><strong>Instructions du service logistique :</strong></p>
                    <p id="detailMotifLogisticien" class="mb-0"></p>
                </div>
                <div id="motifRejetComptableSection" class="alert alert-danger d-none mt-3">
                    <h6 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Dossier Rejeté</h6>
                    <p><strong>Motif du rejet par le comptable :</strong></p>
                    <p id="detailMotifComptable" class="mb-0"></p>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button></div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // --- Logique du Modal de Détails ---
            const viewModal = document.getElementById('viewBesoinModal');
            if (viewModal) {
                viewModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-id'), titre = button.getAttribute('data-titre'), description = button.getAttribute('data-description'), statut = button.getAttribute('data-statut'), date = button.getAttribute('data-date'), fichier = button.getAttribute('data-fichier'), motifLogFin = button.getAttribute('data-motif-log-fin'), motifComptable = button.getAttribute('data-motif-comptable'), montant = button.getAttribute('data-montant');
                    
                    document.getElementById('detailId').textContent = id;
                    document.getElementById('detailTitre').textContent = titre;
                    document.getElementById('detailDescription').textContent = description;
                    document.getElementById('detailDate').textContent = date;
                    
                    const detailMontant = document.getElementById('detailMontant');
                    if (montant && montant !== 'N/A' && montant !== '') {
                        detailMontant.textContent = new Intl.NumberFormat('fr-FR').format(montant) + ' cfa';
                        detailMontant.parentElement.classList.remove('d-none');
                    } else {
                        detailMontant.textContent = 'Non spécifié';
                    }

                    const statutBadge = document.getElementById('detailStatut');
                    statutBadge.textContent = statut;
                    statutBadge.className = 'badge';
                    let badgeClass = 'bg-secondary';
                    if (statut === 'Validé' || statut === 'Marché attribué' || statut === 'Paiement Approuvé') badgeClass = 'bg-success';
                    else if (statut === 'En cours de proforma' || statut === 'En traitement' || statut === 'Facturé') badgeClass = 'bg-primary';
                    else if (statut === 'Rejeté' || statut === 'Rejeté par Comptable' || statut === 'Rejeté par Finance') badgeClass = 'bg-danger';
                    else if (statut === 'En attente de validation' || statut === 'Correction Requise' || statut === 'En attente de Finance') badgeClass = 'bg-warning text-dark';
                    statutBadge.classList.add(badgeClass);

                    const telechargerLien = document.getElementById('detailTelechargerLien'), fichierText = document.getElementById('detailFichierText');
                    if (fichier && fichier !== '') {
                        fichierText.textContent = fichier;
                        telechargerLien.href = './uploads/' + rawurlencode(fichier);
                        telechargerLien.classList.remove('d-none');
                    } else {
                        fichierText.textContent = 'Aucun fichier joint.';
                        telechargerLien.classList.add('d-none');
                    }

                    // Logique d'affichage des motifs de rejet/correction
                    const motifFinanceSection = document.getElementById('motifRejetFinanceSection');
                    const motifLogisticienSection = document.getElementById('motifRejetLogisticienSection');
                    const motifComptableSection = document.getElementById('motifRejetComptableSection');

                    motifFinanceSection.classList.add('d-none');
                    motifLogisticienSection.classList.add('d-none');
                    motifComptableSection.classList.add('d-none');

                    if (statut === 'Rejeté par Finance' && motifLogFin) {
                        document.getElementById('detailMotifFinance').textContent = motifLogFin;
                        motifFinanceSection.classList.remove('d-none');
                    } else if (statut === 'Correction Requise' && motifLogFin) {
                        document.getElementById('detailMotifLogisticien').textContent = motifLogFin;
                        motifLogisticienSection.classList.remove('d-none');
                    } else if (statut === 'Rejeté par Comptable' && motifComptable) {
                        document.getElementById('detailMotifComptable').textContent = motifComptable;
                        motifComptableSection.classList.remove('d-none');
                    }
                });
            }
            
            // --- Logique de Filtrage et Recherche (Dynamique) ---
            const searchInput = document.getElementById('searchTable');
            const statutSelect = document.getElementById('filtreStatut');
            const tableBody = document.querySelector('#historique-besoins tbody');
            const rows = tableBody ? tableBody.querySelectorAll('tr') : [];

            function filterTable() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedStatut = statutSelect.value.toLowerCase();

                rows.forEach(row => {
                    // Les lignes d'erreur/vide sont ignorées
                    if (row.cells.length < 5) return; 
                    
                    const id = row.querySelector('code').textContent.toLowerCase();
                    const titre = row.cells[1].textContent.toLowerCase();
                    // Récupère le statut (le texte du badge)
                    const statutCell = row.cells[3].textContent.toLowerCase(); 
                    
                    let matchesSearch = (id.includes(searchTerm) || titre.includes(searchTerm));
                    let matchesStatus = true;

                    if (selectedStatut) {
                        if (selectedStatut === 'en cours') {
                            matchesStatus = statutCell.includes('attente') || statutCell.includes('correction') || statutCell.includes('cours') || statutCell.includes('traitement') || statutCell.includes('attribué');
                        } else if (selectedStatut === 'approuvé') {
                            matchesStatus = statutCell.includes('validé') || statutCell.includes('paiement approuvé');
                        } else if (selectedStatut === 'rejeté') {
                            matchesStatus = statutCell.includes('rejeté');
                        } else {
                            matchesStatus = statutCell.includes(selectedStatut);
                        }
                    }

                    if (matchesSearch && matchesStatus) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('keyup', filterTable);
            }
            if (statutSelect) {
                statutSelect.addEventListener('change', filterTable);
            }

            // --- Logique des Notifications ---
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) {
                notifDropdown.addEventListener('show.bs.dropdown', function () {
                    const unreadBadge = notifDropdown.querySelector('.badge');
                    if (unreadBadge) {
                        fetch('marquer_notifications_lues.php', { method: 'POST' })
                            .then(response => { 
                                if (response.ok) { 
                                    unreadBadge.remove(); 
                                    document.querySelectorAll('.dropdown-menu .bg-light').forEach(item => { 
                                        item.classList.remove('bg-light', 'fw-bold'); 
                                        item.classList.add('text-muted'); 
                                    }); 
                                } 
                            })
                            .catch(error => console.error('Erreur:', error));
                    }
                });
            }
            
            // Mise à jour des notifications en temps réel
            function checkForUpdates() {
                fetch('check_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        const notifDropdown = document.getElementById('notifDropdown');
                        if (!notifDropdown) return;
                        let notifBadge = notifDropdown.querySelector('.badge');
                        if (data.unread_count > 0) {
                            if (notifBadge) {
                                notifBadge.textContent = data.unread_count;
                            } else {
                                const newBadge = document.createElement('span');
                                newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                                newBadge.textContent = data.unread_count;
                                notifDropdown.appendChild(newBadge);
                            }
                        } else {
                            if (notifBadge) {
                                notifBadge.remove();
                            }
                        }
                    })
                    .catch(error => console.error('Erreur de vérification des notifications:', error));
            }
            setInterval(checkForUpdates, 10000); // Vérification  toutes les 10 secondes
        });

        // Fonction JS pour encoder les URLs (équivalent à rawurlencode de PHP)
        function rawurlencode(str) { 
            str = (str + '').toString();
            return encodeURIComponent(str)
                .replace(/!/g, '%21')
                .replace(/'/g, '%27')
                .replace(/\(/g, '%28')
                .replace(/\)/g, '%29')
                .replace(/\*/g, '%2A');
        }
    </script>
</body>
</html>