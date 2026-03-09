<?php
session_start();
include 'db_connect.php';

// ==================================================================
// 1. SÉCURITÉ & CONFIGURATION
// ==================================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (in_array(strtolower($_SESSION['role']), ['comptable', 'logisticien', 'finance'])) {
    header('Location: unauthorized.php'); 
    exit();
}

$utilisateur_id = $_SESSION['user_id'];
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Utilisateur';

function generateBesoinId() {
    return 'B' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
}

// ==================================================================
// 2A. TRAITEMENT DU FORMULAIRE : CRÉATION (INSERTION)
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_besoin'])) {
    
    $type_besoin = $_POST['type_besoin'] ?? 'B';
    // On garde les clés internes pour la base de données, mais l'affichage change
    $type_demande = ($type_besoin === 'A') ? 'Achat_Direct' : 'Standard';
    
    $projet_nom = $_POST['source_fonds'] ?? '';
    $stmt_proj = $pdo->prepare("SELECT id FROM projets WHERE nom = ? LIMIT 1");
    $stmt_proj->execute([$projet_nom]);
    $projet_id = $stmt_proj->fetchColumn() ?: null;

    $titre = trim($_POST['besoinTitre']);
    $description = "";
    $montant = 0;
    $statut_initial = "En attente de Finance"; 
    $fichier_nom = null;
    $ligne_imputation = null;
    $delai_souhaite = null;
    $confirmation_pro = 0;
    $besoin_assistance = 0;

    if ($type_demande === 'Achat_Direct') {
        $ligne_imputation = $_POST['ligne_imputation'] ?? null;
        $delai_souhaite = !empty($_POST['delai_souhaite']) ? $_POST['delai_souhaite'] : null;
        $confirmation_pro = isset($_POST['confirme_pro']) ? 1 : 0;
        $besoin_assistance = isset($_POST['besoin_assistance']) ? 1 : 0;
        $nb_articles = isset($_POST['art_desig']) ? count(array_filter($_POST['art_desig'])) : 0;
        $description = "Demande de Fourniture - " . $nb_articles . " articles.";
    } else {
        $description = trim($_POST['besoinDescription']);
        $montant = !empty($_POST['besoinMontant']) ? trim($_POST['besoinMontant']) : 0;
        
        if (isset($_FILES['besoinFichier']) && $_FILES['besoinFichier']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['besoinFichier']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'png', 'jpeg'])) {
                $uploadFileDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
                $newFileName = generateBesoinId() . '.' . $ext;
                if (move_uploaded_file($_FILES['besoinFichier']['tmp_name'], $uploadFileDir . $newFileName)) {
                    $fichier_nom = $newFileName;
                }
            }
        }
    }

    try {
        $pdo->beginTransaction();
        $nouvel_id = generateBesoinId();

        $sql = "INSERT INTO besoins (id, titre, description, montant, date_soumission, statut, fichier, utilisateur_id, type_demande, projet_id, ligne_imputation, delai_souhaite, confirmation_pro, besoin_assistance) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nouvel_id, $titre, $description, $montant, $statut_initial, $fichier_nom, $utilisateur_id, $type_demande, $projet_id, $ligne_imputation, $delai_souhaite, $confirmation_pro, $besoin_assistance]);

        if ($type_demande === 'Achat_Direct' && isset($_POST['art_desig'])) {
            $stmt_art = $pdo->prepare("INSERT INTO besoin_articles (besoin_id, designation, unite, quantite, pu_indicatif, prix_total) VALUES (?, ?, ?, ?, ?, ?)");
            $total_general = 0;
            for ($i = 0; $i < count($_POST['art_desig']); $i++) {
                $desig = $_POST['art_desig'][$i];
                if (!empty($desig)) {
                    $unite = $_POST['art_unite'][$i] ?? '';
                    $qte = floatval($_POST['art_qte'][$i] ?? 0);
                    $pu = floatval($_POST['art_pu'][$i] ?? 0);
                    $total_ligne = $qte * $pu;
                    $total_general += $total_ligne;
                    $stmt_art->execute([$nouvel_id, $desig, $unite, $qte, $pu, $total_ligne]);
                }
            }
            $pdo->prepare("UPDATE besoins SET montant = ? WHERE id = ?")->execute([$total_general, $nouvel_id]);
        }

        $role_target = 'finance'; 
        $destinataires = $pdo->query("SELECT id FROM utilisateurs WHERE role = '$role_target'")->fetchAll(PDO::FETCH_COLUMN);
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
        $lien_notif = "finance_view_besoin.php?id=$nouvel_id";
        
        // Texte de notification 
        $type_label = ($type_demande === 'Achat_Direct') ? 'Fourniture' : 'TDR/CDC';
        foreach ($destinataires as $dest_id) {
            $stmt_notif->execute([$dest_id, "Nouveau besoin ($type_label) soumis par $utilisateur_nom", $lien_notif]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Demande enregistrée et transmise à la Finance pour validation !";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur technique : " . $e->getMessage();
    }
    header("Location: chef_projet.php");
    exit();
}

// ==================================================================
// 2B. TRAITEMENT DU FORMULAIRE : CORRECTION (UPDATE)
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_besoin'])) {
    $edit_id = $_POST['edit_id'];
    $type_demande = $_POST['edit_type'];
    
    $projet_id = !empty($_POST['edit_projet_id']) ? $_POST['edit_projet_id'] : null;
    $titre = trim($_POST['edit_titre']);
    $description = "";
    $montant = 0;
    $nouveau_statut = "En attente de Finance"; 
    
    $ligne_imputation = null;
    $delai_souhaite = null;

    if ($type_demande === 'Achat_Direct') {
        $ligne_imputation = $_POST['edit_ligne_imputation'] ?? null;
        $delai_souhaite = !empty($_POST['edit_delai_souhaite']) ? $_POST['edit_delai_souhaite'] : null;
        $nb_articles = isset($_POST['edit_art_desig']) ? count(array_filter($_POST['edit_art_desig'])) : 0;
        $description = "Demande de Fourniture - " . $nb_articles . " articles. (CORRIGÉE)";
    } else {
        $description = trim($_POST['edit_description']);
        $montant = !empty($_POST['edit_montant']) ? trim($_POST['edit_montant']) : 0;
    }

    try {
        $pdo->beginTransaction();

        $fichier_sql = "";
        $params_update = [
            $titre, $description, $montant, $nouveau_statut, $projet_id, 
            $ligne_imputation, $delai_souhaite, $edit_id, $utilisateur_id
        ];

        if ($type_demande === 'Standard' && isset($_FILES['edit_fichier']) && $_FILES['edit_fichier']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['edit_fichier']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'png', 'jpeg'])) {
                $uploadFileDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
                $newFileName = $edit_id . '_V2.' . $ext;
                if (move_uploaded_file($_FILES['edit_fichier']['tmp_name'], $uploadFileDir . $newFileName)) {
                    $fichier_sql = ", fichier = ?";
                    array_splice($params_update, 7, 0, [$newFileName]);
                }
            }
        }

        $sql_update = "UPDATE besoins SET titre=?, description=?, montant=?, statut=?, projet_id=?, ligne_imputation=?, delai_souhaite=? $fichier_sql, motif_rejet=NULL WHERE id=? AND utilisateur_id=?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute($params_update);

        if ($type_demande === 'Achat_Direct') {
            $pdo->prepare("DELETE FROM besoin_articles WHERE besoin_id = ?")->execute([$edit_id]);
            if (isset($_POST['edit_art_desig'])) {
                $stmt_art = $pdo->prepare("INSERT INTO besoin_articles (besoin_id, designation, unite, quantite, pu_indicatif, prix_total) VALUES (?, ?, ?, ?, ?, ?)");
                $total_general = 0;
                for ($i = 0; $i < count($_POST['edit_art_desig']); $i++) {
                    $desig = $_POST['edit_art_desig'][$i];
                    if (!empty($desig)) {
                        $unite = $_POST['edit_art_unite'][$i] ?? '';
                        $qte = floatval($_POST['edit_art_qte'][$i] ?? 0);
                        $pu = floatval($_POST['edit_art_pu'][$i] ?? 0);
                        $total_ligne = $qte * $pu;
                        $total_general += $total_ligne;
                        $stmt_art->execute([$edit_id, $desig, $unite, $qte, $pu, $total_ligne]);
                    }
                }
                $pdo->prepare("UPDATE besoins SET montant = ? WHERE id = ?")->execute([$total_general, $edit_id]);
            }
        }

        $role_target = 'finance'; 
        $destinataires = $pdo->query("SELECT id FROM utilisateurs WHERE role = '$role_target'")->fetchAll(PDO::FETCH_COLUMN);
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
        $lien_notif = "finance_view_besoin.php?id=$edit_id";
        
        foreach ($destinataires as $dest_id) {
            $stmt_notif->execute([$dest_id, "Correction apportée sur demande $edit_id par $utilisateur_nom", $lien_notif]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Demande corrigée et renvoyée à la Finance avec succès !";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur lors de la correction : " . $e->getMessage();
    }
    header("Location: chef_projet.php");
    exit();
}

// ==================================================================
// 3. CHARGEMENT DES DONNÉES
// ==================================================================
try { $projets = $pdo->query("SELECT * FROM projets WHERE statut = 'Actif' ORDER BY nom ASC")->fetchAll(); } catch (PDOException $e) { $projets = []; }

$notifications = [];
$unread_count = 0;
try {
    $sql_notif = "SELECT * FROM notifications 
                  WHERE utilisateur_id = ? 
                  AND (lue = 0 OR date_lecture > DATE_SUB(NOW(), INTERVAL 1 DAY)) 
                  ORDER BY date_creation DESC LIMIT 15";
    $stmt_notif = $pdo->prepare($sql_notif);
    $stmt_notif->execute([$utilisateur_id]);
    $notifications = $stmt_notif->fetchAll();
    
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lue = 0");
    $stmt_count->execute([$utilisateur_id]);
    $unread_count = $stmt_count->fetchColumn();
} catch (PDOException $e) { }

$compteurs = ['envoyees' => 0, 'en_cours' => 0, 'approuvees' => 0, 'rejetees' => 0];
try {
    $stmt_compteurs = $pdo->prepare("SELECT statut, COUNT(*) as total FROM besoins WHERE utilisateur_id = ? GROUP BY statut");
    $stmt_compteurs->execute([$utilisateur_id]);
    foreach ($stmt_compteurs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $compteurs['envoyees'] += $row['total'];
        if (strpos($row['statut'], 'attente') !== false || strpos($row['statut'], 'cours') !== false || strpos($row['statut'], 'Correction') !== false || strpos($row['statut'], 'lancé') !== false) {
            $compteurs['en_cours'] += $row['total'];
        } elseif (strpos($row['statut'], 'Valid') !== false || strpos($row['statut'], 'Approuv') !== false || strpos($row['statut'], 'Marché') !== false || strpos($row['statut'], 'Facturé') !== false) {
            $compteurs['approuvees'] += $row['total'];
        } elseif (strpos($row['statut'], 'Rejet') !== false) {
            $compteurs['rejetees'] += $row['total'];
        }
    }
} catch (PDOException $e) { }

try {
    $sql_hist = "SELECT b.*, (SELECT nom FROM projets WHERE id = b.projet_id LIMIT 1) as nom_projet 
                 FROM besoins b 
                 WHERE b.utilisateur_id = ? 
                 ORDER BY b.date_soumission DESC";
    $stmt_hist = $pdo->prepare($sql_hist);
    $stmt_hist->execute([$utilisateur_id]);
    $historique = $stmt_hist->fetchAll();

    foreach ($historique as $key => $row) {
        $historique[$key]['articles_json'] = '[]';
        if ($row['type_demande'] === 'Achat_Direct') {
            try {
                $stmt_art = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
                $stmt_art->execute([$row['id']]);
                $arts = $stmt_art->fetchAll(PDO::FETCH_ASSOC);
                $historique[$key]['articles_json'] = json_encode($arts, JSON_HEX_APOS | JSON_HEX_QUOT);
            } catch (Exception $e) { }
        }
    }
} catch (PDOException $e) { $historique = []; }

function get_status_badge($statut) {
    $badges = [
        'En attente de Finance' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass me-1"></i> Attente Finance</span>',
        'En attente de validation' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i> Attente Logistique</span>',
        'Validé' => '<span class="badge bg-info text-dark"><i class="bi bi-gear-fill me-1"></i> Traitement Log.</span>',
        'En cours de proforma' => '<span class="badge bg-primary"><i class="bi bi-envelope-paper me-1"></i> Proforma en cours</span>',
        'Appel d\'offres lancé' => '<span class="badge bg-dark"><i class="bi bi-megaphone me-1"></i> AO Lancé</span>',
        'Marché attribué' => '<span class="badge bg-success"><i class="bi bi-award me-1"></i> Marché attribué</span>',
        'Facturé' => '<span class="badge bg-secondary"><i class="bi bi-send-check me-1"></i> Transmis Compta</span>',
        'Facture Validée' => '<span class="badge bg-success bg-opacity-75 text-white"><i class="bi bi-check-all me-1"></i> Facture Validée</span>',
        'Paiement Approuvé' => '<span class="badge bg-dark text-white"><i class="bi bi-check-all me-1"></i> Payé & Clos</span>',
        'Correction Requise' => '<span class="badge bg-danger"><i class="bi bi-pencil-square me-1"></i> À Corriger</span>',
        'Rejeté' => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Rejeté</span>',
        'Rejeté par Finance' => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Rejeté Finance</span>',
        'Rejeté par Comptable' => '<span class="badge bg-danger"><i class="bi bi-exclamation-octagon me-1"></i> Rejeté Compta</span>'
    ];
    return $badges[$statut] ?? '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Demandeur - Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-width: 260px; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 5px rgba(0,0,0,0.05); transition: 0.3s; }
        .sidebar .p-4 { background-color: #0d6efd; color: white; }
        .sidebar .nav-link { color: #495057; border-radius: 8px; padding: 10px 15px; }
        .sidebar .nav-link:hover { background-color: #f8f9fa; color: #0d6efd; }
        .sidebar .nav-link.active { background-color: #0d6efd; color: white; font-weight: 600; }
        
        /* DESIGN CARTES D'OPTION */
        .option-card { cursor: pointer; transition: all 0.3s ease; border: 2px solid #e9ecef; border-radius: 12px; background-color: #fff; }
        .option-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        
        /* OPTION A (FOURNITURE) - BLEU */
        #optA:checked + .option-card { border-color: #0d6efd; background-color: #f0f7ff; box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2); }
        #optA:checked + .option-card i { color: #0d6efd !important; }
        
        /* OPTION B (TDR/CDC) - VIOLET  */
        #optB:checked + .option-card { border-color: #6f42c1; background-color: #f3f0ff; box-shadow: 0 4px 12px rgba(111, 66, 193, 0.2); }
        #optB:checked + .option-card i { color: #6f42c1 !important; }

        .dropdown-menu-notify { width: 320px; max-height: 400px; overflow-y: auto; }
        .notify-item { white-space: normal; word-wrap: break-word; border-bottom: 1px solid #f0f0f0; }
        .notify-item:hover { background-color: #f8f9fa; }
        .table-input { border: none; background: transparent; width: 100%; text-align: center; }
        .table-input:focus { outline: none; background: #e9ecef; }
        @media (max-width: 768px) { .d-flex.vh-100 { flex-direction: column; } .sidebar { width: 100%; height: auto; position: relative; } .collapse:not(.show) { display: none !important; } }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <div class="collapse d-md-block" id="sidebarCollapse">
        <nav class="sidebar bg-white border-end">
            <div class="p-4 border-bottom"><h5 class="mb-1">Swisscontact</h5><small class="opacity-75">Portail Demandeur</small></div>
            <div class="p-3">
                <ul class="nav nav-pills flex-column"><li class="nav-item mb-1"><a class="nav-link active" href="chef_projet.php"><i class="bi bi-grid me-2"></i>Tableau de bord</a></li></ul>
                <div class="mt-4"><button class="btn btn-primary shadow-sm w-100 text-start py-2" data-bs-toggle="modal" data-bs-target="#newBesoinModal"><i class="bi bi-plus-circle me-2"></i>Nouveau Besoin</button></div>
            </div>
        </nav>
    </div>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center">
            <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse"><i class="bi bi-list"></i></button>
            <div><h2 class="h5 mb-0 fw-bold">Tableau de Bord</h2><small class="text-muted">Initiateur : <?= htmlspecialchars($utilisateur_nom) ?></small></div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" id="notifDropdown">
                        <i class="bi bi-bell"></i>
                        <?php if ($unread_count > 0): ?><span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread_count ?></span><?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-notify shadow" aria-labelledby="notifDropdown">
                        <li class="dropdown-header fw-bold text-dark">Notifications récentes</li>
                        <?php if (empty($notifications)): ?>
                            <li class="p-3 text-center text-muted small">Aucune notification récente</li>
                        <?php else: foreach ($notifications as $n): ?>
                            <li>
                                <a href="<?= $n['lien'] ?? '#' ?>" class="dropdown-item notify-item py-2 <?= $n['lue'] ? 'text-muted' : 'bg-light fw-bold' ?>">
                                    <div class="small"><?= htmlspecialchars($n['message']) ?></div>
                                    <div class="text-primary mt-1" style="font-size: 0.75rem"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($n['date_creation'])) ?></div>
                                </a>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light rounded-circle bg-primary text-white" style="width:35px;height:35px" data-bs-toggle="dropdown"><?= strtoupper(substr($utilisateur_nom,0,1)) ?></button>
                    <ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item text-danger" href="deconnexion.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li></ul>
                </div>
            </div>
        </header>

        <main class="p-4 flex-fill overflow-auto">
            <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card bg-primary text-white shadow-sm h-100 border-0"><div class="card-body d-flex justify-content-between align-items-center"><div><h3 class="mb-0 fw-bold"><?= $compteurs['envoyees'] ?></h3><small>Total Soumises</small></div><i class="bi bi-send fs-1 opacity-50"></i></div></div></div>
                <div class="col-md-3"><div class="card bg-warning text-dark shadow-sm h-100 border-0"><div class="card-body d-flex justify-content-between align-items-center"><div><h3 class="mb-0 fw-bold"><?= $compteurs['en_cours'] ?></h3><small>En Cours</small></div><i class="bi bi-hourglass-split fs-1 opacity-50"></i></div></div></div>
                <div class="col-md-3"><div class="card bg-success text-white shadow-sm h-100 border-0"><div class="card-body d-flex justify-content-between align-items-center"><div><h3 class="mb-0 fw-bold"><?= $compteurs['approuvees'] ?></h3><small>Validées / Payées</small></div><i class="bi bi-check-circle fs-1 opacity-50"></i></div></div></div>
                <div class="col-md-3"><div class="card bg-danger text-white shadow-sm h-100 border-0"><div class="card-body d-flex justify-content-between align-items-center"><div><h3 class="mb-0 fw-bold"><?= $compteurs['rejetees'] ?></h3><small>Rejetées</small></div><i class="bi bi-x-circle fs-1 opacity-50"></i></div></div></div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-0">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4"><h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Historique des demandes</h5></div>
                        
                        <div class="col-md-3">
                            <select id="filterType" class="form-select form-select-sm shadow-sm">
                                <option value="">Tous les types</option>
                                <option value="Standard">TDR / CDC</option>
                                <option value="Achat_Direct">Fourniture</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <select id="filterStatut" class="form-select form-select-sm shadow-sm">
                                <option value="">Tous les statuts</option>
                                <option value="attente">En attente (Finance/Logistique)</option>
                                <option value="cours">En cours (Proforma/AO)</option>
                                <option value="Valid">Validé / Marché / Facturé</option>
                                <option value="Approuv">Payé & Clôturé</option>
                                <option value="Rejet">Rejeté</option>
                                <option value="Correction">À Corriger</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="searchTable" class="form-control border-start-0 ps-0" placeholder="Chercher...">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tableHistorique">
                        <thead class="table-light small text-muted text-uppercase"><tr><th class="ps-4">ID</th><th>Date</th><th>Type</th><th>Titre / Projet</th><th>Montant</th><th>Statut</th><th class="text-end pe-4">Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($historique)): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-folder2-open fs-1 d-block mb-3"></i>Aucune demande soumise.</td></tr>
                            <?php else: foreach ($historique as $row): ?>
                                <tr data-type="<?= htmlspecialchars($row['type_demande']) ?>" data-statut="<?= htmlspecialchars($row['statut']) ?>">
                                    <td class="ps-4"><code class="text-dark bg-light px-2 py-1 rounded"><?= htmlspecialchars($row['id']) ?></code></td>
                                    <td><?= date('d/m/Y', strtotime($row['date_soumission'])) ?></td>
                                    <td><?= ($row['type_demande'] == 'Achat_Direct') ? '<span class="badge bg-light text-primary border border-primary">Fourniture</span>' : '<span class="badge bg-light text-secondary border">TDR / CDC</span>' ?></td>
                                    <td><strong><?= htmlspecialchars($row['titre']) ?></strong><br><small class="text-muted"><i class="bi bi-briefcase me-1"></i><?= htmlspecialchars($row['nom_projet'] ?? 'Non spécifié') ?></small></td>
                                    <td class="fw-bold"><?= number_format($row['montant'], 0, ',', ' ') ?> CFA</td>
                                    <td><?= get_status_badge($row['statut']) ?></td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-dark shadow-sm btn-view" 
                                                data-bs-toggle="modal" data-bs-target="#viewBesoinModal"
                                                data-id="<?= htmlspecialchars($row['id']) ?>"
                                                data-date="<?= htmlspecialchars(date('d/m/Y', strtotime($row['date_soumission']))) ?>"
                                                data-projet="<?= htmlspecialchars($row['nom_projet'] ?? 'N/A') ?>"
                                                data-titre="<?= htmlspecialchars($row['titre']) ?>"
                                                data-type="<?= htmlspecialchars($row['type_demande']) ?>"
                                                data-desc="<?= htmlspecialchars($row['description']) ?>"
                                                data-imp="<?= htmlspecialchars($row['ligne_imputation'] ?? '') ?>"
                                                data-delai="<?= htmlspecialchars($row['delai_souhaite'] ?? '') ?>"
                                                data-motif="<?= htmlspecialchars($row['motif_rejet'] ?? '') ?>"
                                                data-statut="<?= htmlspecialchars($row['statut']) ?>"
                                                data-montant="<?= htmlspecialchars($row['montant']) ?>"
                                                data-fichier="<?= htmlspecialchars($row['fichier'] ?? '') ?>"
                                                data-articles="<?= htmlspecialchars($row['articles_json'], ENT_QUOTES, 'UTF-8') ?>"
                                                title="Voir les détails"><i class="bi bi-eye"></i>
                                        </button>
                                        <?php if (strpos($row['statut'], 'Correction') !== false): ?>
                                            <button class="btn btn-sm btn-warning shadow-sm fw-bold text-dark ms-1" 
                                                    data-bs-toggle="modal" data-bs-target="#editBesoinModal"
                                                    data-id="<?= htmlspecialchars($row['id']) ?>"
                                                    data-projet_id="<?= htmlspecialchars($row['projet_id'] ?? '') ?>"
                                                    data-titre="<?= htmlspecialchars($row['titre']) ?>"
                                                    data-type="<?= htmlspecialchars($row['type_demande']) ?>"
                                                    data-desc="<?= htmlspecialchars($row['description']) ?>"
                                                    data-imp="<?= htmlspecialchars($row['ligne_imputation'] ?? '') ?>"
                                                    data-delai="<?= htmlspecialchars($row['delai_souhaite'] ?? '') ?>"
                                                    data-montant="<?= htmlspecialchars($row['montant']) ?>"
                                                    data-articles="<?= htmlspecialchars($row['articles_json'], ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Corriger la demande"><i class="bi bi-pencil me-1"></i>Corriger
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="newBesoinModal" tabindex="-1">
    <div class="modal-dialog modal-xl shadow-lg">
        <div class="modal-content border-0">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="fw-bold m-0"><i class="bi bi-cart-plus me-2"></i>Expression de besoin </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="chef_projet.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4 bg-light">
                    <div class="row mb-4 justify-content-center">
                        <div class="col-md-5">
                            <input type="radio" class="btn-check" name="type_besoin" id="optA" value="A" checked onclick="toggleBesoin('A')">
                            <label class="card h-100 option-card p-4 text-center" for="optA">
                                <div class="mb-3"><i class="bi bi-box-seam-fill fs-1 text-muted"></i></div>
                                <h5 class="fw-bold text-dark">Option A : Fourniture</h5>
                                <p class="text-muted small mb-0">Matériels, équipements, consommables, biens physiques.</p>
                            </label>
                        </div>
                        <div class="col-md-5">
                            <input type="radio" class="btn-check" name="type_besoin" id="optB" value="B" onclick="toggleBesoin('B')">
                            <label class="card h-100 option-card p-4 text-center" for="optB">
                                <div class="mb-3"><i class="bi bi-file-text-fill fs-1 text-muted"></i></div>
                                <h5 class="fw-bold text-dark">Option B : TDR / CDC</h5>
                                <p class="text-muted small mb-0">Prestations de services, consultants, travaux intellectuels.</p>
                            </label>
                        </div>
                    </div>
                    
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label fw-bold small text-muted">PROVENANCE DES FONDS <span class="text-danger">*</span></label><select name="source_fonds" class="form-select border-primary bg-light" required><option value="Choisir">Choisir </option><?php foreach($projets as $pj) echo "<option value='".htmlspecialchars($pj['nom'])."'>".htmlspecialchars($pj['nom'])."</option>"; ?></select></div>
                                <div class="col-md-6"><label class="form-label fw-bold small text-muted"> TITRE DU BESOIN <span class="text-danger">*</span></label><input type="text" name="besoinTitre" class="form-control bg-light" placeholder="Ex: Achat fournitures bureau..." required></div>
                            </div>
                        </div>
                    </div>

                    <div id="sectionA" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="alert alert-primary py-2 px-3 small border-0 bg-opacity-10 text-primary mb-4 fw-bold">
                                <i class="bi bi-info-circle-fill me-2"></i>Formulaire Fourniture (Liste d'articles)
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4"><label class="form-label small text-muted fw-bold">LIGNE D'IMPUTATION</label><input type="text" name="ligne_imputation" class="form-control bg-light"></div>
                                <div class="col-md-4"><label class="form-label small text-muted fw-bold">DÉLAI DE LIVRAISON SOUHAITÉ</label><input type="date" name="delai_souhaite" class="form-control border-danger bg-light" min="<?= date('Y-m-d') ?>"></div>
                            </div>
                            <h6 class="fw-bold mb-3 mt-4 border-bottom pb-2 text-primary">Liste des articles</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle" id="tableArticles">
                                    <thead class="table-light text-muted small"><tr><th>Désignation exacte</th><th width="15%">Unité</th><th width="10%">Qté</th><th width="18%">PU Indicatif (CFA)</th><th width="18%">Total</th><th width="5%"></th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <td><input type="text" name="art_desig[]" class="form-control form-control-sm"></td>
                                            <td><input type="text" name="art_unite[]" class="form-control form-control-sm" placeholder="Pièce, Kit..."></td>
                                            <td><input type="number" name="art_qte[]" class="form-control form-control-sm qte" oninput="calcTotal(this)"></td>
                                            <td><input type="number" name="art_pu[]" class="form-control form-control-sm pu" oninput="calcTotal(this)"></td>
                                            <td><input type="text" class="form-control form-control-sm row-total fw-bold text-success bg-white border-0" readonly></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addLigne()"><i class="bi bi-plus-lg me-1"></i> Ajouter article</button>
                            <div class="mt-4 bg-light p-3 rounded border border-warning small">
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirme_pro" id="c1"><label class="form-check-label fw-bold" for="c1">Le détail des articles et les prix ont été confirmés par un professionnel technique.</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="besoin_assistance" id="c2"><label class="form-check-label" for="c2">J'aurai besoin d’une assistance technique lors de la vérification à la livraison.</label></div>
                            </div>
                        </div>
                    </div>

                    <div id="sectionB" class="card border-0 shadow-sm" style="display:none;">
                        <div class="card-body">
                            <div class="alert alert-success py-2 px-3 small border-0 bg-opacity-10 text-success mb-4 fw-bold">
                                <i class="bi bi-info-circle-fill me-2"></i>Formulaire TDR / CDC 
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3"><label class="form-label small fw-bold text-muted">MONTANT GLOBAL ESTIMATIF (CFA) <span class="text-danger">*</span></label><input type="number" name="besoinMontant" class="form-control form-control-lg text-success fw-bold bg-light" placeholder="Ex: 5000000"></div>
                                <div class="col-md-6 mb-3"><label class="form-label small fw-bold text-muted"><i class="bi bi-paperclip me-1"></i>PIÈCE JOINTE (TDR, CDC) <span class="text-danger">*</span></label><input type="file" name="besoinFichier" class="form-control form-control-lg bg-light" accept=".pdf,.jpg,.png,.doc,.docx"></div>
                            </div>
                            <div class="mb-2 mt-2"><label class="form-label small fw-bold text-muted"> JUSTIFICATION <span class="text-danger">*</span></label><textarea name="besoinDescription" class="form-control bg-light" rows="4" placeholder="Expliquez clairement votre besoin et le contexte..."></textarea></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0 pt-0 pb-4 pe-4">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="submit_besoin" class="btn btn-primary px-5 fw-bold shadow"><i class="bi bi-send-fill me-2"></i> Soumettre à la Finance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewBesoinModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light pb-2">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-folder2-open me-2 text-primary"></i>Dossier <code id="vId" class="text-primary bg-white border px-2 py-1 rounded"></code></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="row mb-4 text-center border-bottom pb-4">
                    <div class="col-4 border-end"><small class="text-muted d-block text-uppercase fw-bold mb-1">Statut Actuel</small><div id="vStatutContainer"></div></div>
                    <div class="col-4 border-end"><small class="text-muted d-block text-uppercase fw-bold mb-1">Date Soumission</small><strong id="vDate" class="fs-6"></strong></div>
                    <div class="col-4"><small class="text-muted d-block text-uppercase fw-bold mb-1">Imputation</small><strong id="vProjet" class="fs-6 text-primary"></strong></div>
                </div>
                <div id="vMotifBox" class="alert alert-danger d-none mb-4 shadow-sm border-danger"><h6 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Motif du blocage / Rejet :</h6><span id="vMotifText"></span></div>
                <div id="vSectionA" class="d-none">
                    <div class="row bg-light p-3 rounded mb-4 shadow-sm border-start border-primary border-4"><div class="col-6"><small class="text-muted d-block text-uppercase mb-1">Ligne d'imputation</small><span id="vImp" class="fw-bold"></span></div><div class="col-6 text-end"><small class="text-muted d-block text-uppercase mb-1">Délai de livraison souhaité</small><span id="vDelai" class="fw-bold text-danger"></span></div></div>
                    <h6 class="fw-bold mb-3"><i class="bi bi-list-check me-2"></i>Articles demandés</h6>
                    <div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead class="table-light text-muted small"><tr><th>Désignation</th><th width="80">Unité</th><th width="60">Qté</th><th width="120">PU Indicatif</th><th width="120">Total</th></tr></thead><tbody id="vArtBody"></tbody></table></div>
                </div>
                <div id="vSectionB" class="d-none">
                    <div class="card bg-light border-0 mb-4"><div class="card-body"><div class="row align-items-center"><div class="col-8"><span class="badge bg-secondary mb-2" id="vTitreDemandeBadge">Option B : Standard</span><h5 class="fw-bold text-dark mb-0" id="vTitreDemande"></h5></div><div class="col-4 text-end border-start"><small class="text-muted d-block text-uppercase fw-bold mb-1">Budget Estimé</small><h4 class="text-success fw-bold mb-0" id="vMontant"></h4></div></div></div></div>
                    <h6 class="fw-bold mb-2">Description / TDR :</h6><div class="p-3 bg-light rounded border text-dark" id="vDesc" style="white-space:pre-wrap; font-size:0.95rem;"></div>
                </div>
                <div id="vFileSec" class="mt-4 d-none text-center bg-light p-3 rounded border"><i class="bi bi-file-earmark-pdf-fill fs-2 text-danger d-block mb-2"></i><label class="small text-muted fw-bold d-block mb-2">Document rattaché (TDR, Devis...)</label><a id="vFileLink" href="#" class="btn btn-primary fw-bold px-4" download><i class="bi bi-download me-2"></i>Télécharger le fichier</a></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editBesoinModal" tabindex="-1">
    <div class="modal-dialog modal-xl shadow-lg">
        <div class="modal-content border-0">
            <div class="modal-header bg-warning text-dark border-0 pb-3">
                <h5 class="fw-bold m-0"><i class="bi bi-pencil-square me-2"></i>Corriger la demande <code id="eHeaderId" class="bg-white px-2 py-1 rounded border"></code></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="chef_projet.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="update_besoin" value="1"><input type="hidden" name="edit_id" id="editId"><input type="hidden" name="edit_type" id="editType">
                <div class="modal-body p-4 bg-light">
                    <div class="alert alert-warning py-2 small mb-4 shadow-sm border-warning text-dark fw-bold"><i class="bi bi-info-circle-fill me-1"></i> Ce dossier a été rejeté ou nécessite une correction. Ajustez les informations ci-dessous et renvoyez-le à la Finance.</div>
                    <div class="card border-0 shadow-sm mb-4"><div class="card-body row g-3"><div class="col-md-6"><label class="form-label fw-bold small text-muted">PROJET IMPUTÉ</label><select name="edit_projet_id" id="editProjet" class="form-select bg-light" required><option value="">-- Sélectionner --</option><?php foreach($projets as $pj) echo "<option value='".htmlspecialchars($pj['id'])."'>".htmlspecialchars($pj['nom'])."</option>"; ?></select></div><div class="col-md-6"><label class="form-label fw-bold small text-muted">TITRE DU BESOIN</label><input type="text" name="edit_titre" id="editTitre" class="form-control bg-light" required></div></div></div>
                    <div id="eSectionA" class="d-none card border-0 shadow-sm"><div class="card-body"><div class="row g-3 mb-4"><div class="col-md-6"><label class="form-label small text-muted fw-bold">Ligne d'imputation</label><input type="text" name="edit_ligne_imputation" id="editImp" class="form-control bg-light"></div><div class="col-md-6"><label class="form-label small text-muted fw-bold">Délai souhaité</label><input type="date" name="edit_delai_souhaite" id="editDelai" class="form-control border-danger bg-light"></div></div><div class="table-responsive border rounded mb-3"><table class="table table-sm align-middle mb-0" id="editTableArticles"><thead class="table-light text-muted small"><tr><th>Désignation</th><th>Unité</th><th width="10%">Qté</th><th width="20%">PU Indicatif</th><th width="20%">Total</th><th></th></tr></thead><tbody id="editTbodyArticles" class="bg-white"></tbody></table></div><button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="addEditLigne()"><i class="bi bi-plus-lg me-1"></i> Ajouter article</button></div></div>
                    <div id="eSectionB" class="d-none card border-0 shadow-sm"><div class="card-body"><div class="mb-3"><label class="form-label fw-bold small text-muted">Montant Estimatif (CFA)</label><input type="number" name="edit_montant" id="editMontant" class="form-control form-control-lg text-success fw-bold bg-light"></div><div class="mb-3"><label class="form-label small fw-bold text-muted">Description détaillée</label><textarea name="edit_description" id="editDesc" class="form-control bg-light" rows="5"></textarea></div><div class="mt-4 pt-3 border-top" id="eFileContainer"><label class="form-label small fw-bold text-muted"><i class="bi bi-paperclip me-1"></i>Remplacer la pièce jointe (Optionnel)</label><input type="file" name="edit_fichier" class="form-control bg-light" accept=".pdf,.doc,.docx,.jpg,.png"><small class="text-secondary d-block mt-1">Laissez vide pour conserver le document précédent.</small></div></div></div>
                </div>
                <div class="modal-footer bg-white pb-4 pe-4 border-0"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-warning px-5 fw-bold shadow"><i class="bi bi-arrow-repeat me-2"></i> Renvoyer à la Finance</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- GESTION NOTIFICATIONS ---
    function markNotificationsRead() {
        const badge = document.getElementById('notifBadge');
        if (badge) {
            fetch('marquer_notifications_lues.php', { method: 'POST' }).then(res => {
                if(res.ok) {
                    badge.remove();
                    document.querySelectorAll('.notify-item .fw-bold').forEach(el => {
                        el.classList.remove('fw-bold'); el.classList.add('text-muted');
                    });
                }
            });
        }
    }

    function checkForUpdates() {
        fetch('check_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifBadge = document.getElementById('notifBadge');
                const notifBtn = document.getElementById('notifDropdown');
                if (data.unread_count > 0) {
                    if (notifBadge) notifBadge.textContent = data.unread_count;
                    else if (notifBtn) {
                        const newBadge = document.createElement('span');
                        newBadge.id = 'notifBadge';
                        newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        newBadge.textContent = data.unread_count;
                        notifBtn.appendChild(newBadge);
                    }
                } else if (notifBadge) notifBadge.remove();
            }).catch(e => console.error(e));
    }
    setInterval(checkForUpdates, 10000);

    const notifBtn = document.getElementById('notifDropdown');
    if(notifBtn) notifBtn.addEventListener('show.bs.dropdown', markNotificationsRead);


    // --- FILTRES TABLEAU ---
    function cleanText(str) {
        if (!str) return "";
        return str.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
    }

    function applyFilters() {
        const vType = cleanText(document.getElementById('filterType').value);
        const vStatut = cleanText(document.getElementById('filterStatut').value);
        const vSearch = cleanText(document.getElementById('searchTable').value);

        const rows = document.querySelectorAll('#tableHistorique tbody tr');

        rows.forEach(row => {
            const rowTypeRaw = row.getAttribute('data-type') || "";
            const rowStatutRaw = row.getAttribute('data-statut') || "";
            const rowTextRaw = row.textContent || "";

            const rowType = cleanText(rowTypeRaw);
            const rowStatut = cleanText(rowStatutRaw);
            const rowText = cleanText(rowTextRaw);

            let matchStatut = false;
            if (vStatut === '') { matchStatut = true; } 
            else if (vStatut === 'attente') { matchStatut = rowStatut.includes('attente'); } 
            else if (vStatut === 'cours') { matchStatut = rowStatut.includes('cours') || rowStatut.includes('lance') || rowStatut.includes('traitement'); } 
            else if (vStatut === 'valid') { matchStatut = rowStatut.includes('valid') || rowStatut.includes('marche') || rowStatut.includes('factur'); } 
            else if (vStatut === 'approuv') { matchStatut = rowStatut.includes('approuv') || rowStatut.includes('paye'); } 
            else { matchStatut = rowStatut.includes(vStatut); }

            const matchType = (vType === '' || rowType === vType);
            const matchSearch = (vSearch === '' || rowText.includes(vSearch));

            row.style.display = (matchType && matchStatut && matchSearch) ? '' : 'none';
        });
    }

    document.getElementById('filterType').addEventListener('change', applyFilters);
    document.getElementById('filterStatut').addEventListener('change', applyFilters);
    document.getElementById('searchTable').addEventListener('input', applyFilters);


    // --- FONCTIONS FORMULAIRES ---
    function toggleBesoin(type) {
        document.getElementById('sectionA').style.display = (type === 'A') ? 'block' : 'none';
        document.getElementById('sectionB').style.display = (type === 'B') ? 'block' : 'none';
    }

    function addLigne() {
        const row = document.querySelector('#tableArticles tbody').insertRow();
        row.innerHTML = `<td><input type="text" name="art_desig[]" class="form-control form-control-sm border-0 bg-light" required></td><td><input type="text" name="art_unite[]" class="form-control form-control-sm border-0 bg-light"></td><td><input type="number" name="art_qte[]" class="form-control form-control-sm border-0 bg-light qte" oninput="calcTotal(this)" required></td><td><input type="number" name="art_pu[]" class="form-control form-control-sm border-0 bg-light pu" oninput="calcTotal(this)"></td><td><input type="text" class="form-control form-control-sm border-0 text-success fw-bold row-total" readonly></td><td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-circle-fill fs-5"></i></button></td>`;
    }

    function calcTotal(input) {
        const tr = input.closest('tr');
        const qte = tr.querySelector('.qte').value || 0;
        const pu = tr.querySelector('.pu').value || 0;
        tr.querySelector('.row-total').value = (qte * pu).toLocaleString('fr-FR') + " CFA";
    }

    function addEditLigne(desig = '', unite = '', qte = '', pu = '') {
        const tbody = document.getElementById('editTbodyArticles');
        const row = tbody.insertRow();
        const total = (qte && pu) ? (qte * pu).toLocaleString('fr-FR') + " CFA" : "";
        row.innerHTML = `<td><input type="text" name="edit_art_desig[]" class="form-control form-control-sm border-0 bg-light" value="${desig}"></td><td><input type="text" name="edit_art_unite[]" class="form-control form-control-sm border-0 bg-light" value="${unite}"></td><td><input type="number" name="edit_art_qte[]" class="form-control form-control-sm border-0 bg-light qte" value="${qte}" oninput="calcTotal(this)"></td><td><input type="number" name="edit_art_pu[]" class="form-control form-control-sm border-0 bg-light pu" value="${pu}" oninput="calcTotal(this)"></td><td><input type="text" class="form-control form-control-sm text-success fw-bold border-0 row-total" value="${total}" readonly></td><td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-circle-fill fs-5"></i></button></td>`;
    }
    
    // --- GESTION DES MODALS (Voir & Editer) ---
    document.getElementById('viewBesoinModal').addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('vId').textContent = btn.getAttribute('data-id');
        document.getElementById('vDate').textContent = btn.getAttribute('data-date');
        document.getElementById('vProjet').textContent = btn.getAttribute('data-projet');
        
        let htmlBadge = '<span class="badge bg-secondary">' + btn.getAttribute('data-statut') + '</span>';
        if (btn.getAttribute('data-statut').includes('Valid') || btn.getAttribute('data-statut').includes('Approuv')) htmlBadge = '<span class="badge bg-success">' + btn.getAttribute('data-statut') + '</span>';
        else if (btn.getAttribute('data-statut').includes('Rejet')) htmlBadge = '<span class="badge bg-danger">' + btn.getAttribute('data-statut') + '</span>';
        document.getElementById('vStatutContainer').innerHTML = htmlBadge;

        const motif = btn.getAttribute('data-motif');
        const motifBox = document.getElementById('vMotifBox');
        if(motif && motif !== 'null' && motif.trim() !== '') {
            document.getElementById('vMotifText').textContent = motif;
            motifBox.classList.remove('d-none');
        } else {
            motifBox.classList.add('d-none');
        }

        const fichier = btn.getAttribute('data-fichier');
        const fileSec = document.getElementById('vFileSec');
        if (fichier && fichier !== 'null' && fichier !== '') {
            document.getElementById('vFileLink').href = 'uploads/' + fichier;
            fileSec.classList.remove('d-none');
        } else {
            fileSec.classList.add('d-none');
        }

        const type = btn.getAttribute('data-type');
        if (type === 'Achat_Direct') {
            document.getElementById('vSectionA').classList.remove('d-none');
            document.getElementById('vSectionB').classList.add('d-none');
            document.getElementById('vImp').textContent = btn.getAttribute('data-imp') || 'Non défini';
            document.getElementById('vDelai').textContent = btn.getAttribute('data-delai') ? new Date(btn.getAttribute('data-delai')).toLocaleDateString('fr-FR') : '-';
            
            document.getElementById('vTitreDemandeBadge').textContent = 'Option A : Fourniture';
            document.getElementById('vTitreDemandeBadge').className = 'badge bg-primary mb-2';

            const tbody = document.getElementById('vArtBody'); tbody.innerHTML = '';
            try { JSON.parse(btn.getAttribute('data-articles')).forEach(a => tbody.innerHTML += `<tr><td>${a.designation}</td><td>${a.unite}</td><td>${a.quantite}</td><td>${a.pu_indicatif}</td><td>${a.prix_total}</td></tr>`); } catch(e){}
        } else {
            document.getElementById('vSectionA').classList.add('d-none');
            document.getElementById('vSectionB').classList.remove('d-none');
            document.getElementById('vTitreDemande').textContent = btn.getAttribute('data-titre');
            
            document.getElementById('vTitreDemandeBadge').textContent = 'Option B : TDR / CDC';
            document.getElementById('vTitreDemandeBadge').className = 'badge bg-secondary mb-2';

            document.getElementById('vMontant').textContent = btn.getAttribute('data-montant') + ' CFA';
            document.getElementById('vDesc').textContent = btn.getAttribute('data-desc');
        }
    });

    document.getElementById('editBesoinModal').addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        
        // ID et Type de base
        document.getElementById('editId').value = btn.getAttribute('data-id');
        document.getElementById('eHeaderId').textContent = btn.getAttribute('data-id');
        document.getElementById('editType').value = btn.getAttribute('data-type');
        document.getElementById('editTitre').value = btn.getAttribute('data-titre');
        
        // CORRECTION MAJEURE ICI : Assignation des valeurs pour le Projet, l'Imputation et le Délai
        document.getElementById('editProjet').value = btn.getAttribute('data-projet_id');
        document.getElementById('editImp').value = btn.getAttribute('data-imp');
        document.getElementById('editDelai').value = btn.getAttribute('data-delai');
        
        if (btn.getAttribute('data-type') === 'Achat_Direct') {
            document.getElementById('eSectionA').classList.remove('d-none');
            document.getElementById('eSectionB').classList.add('d-none');
            
            document.getElementById('editTbodyArticles').innerHTML = '';
            try { 
                JSON.parse(btn.getAttribute('data-articles')).forEach(a => addEditLigne(a.designation, a.unite, a.quantite, a.pu_indicatif)); 
            } catch(e){ 
                addEditLigne(); 
            }
        } else {
            document.getElementById('eSectionA').classList.add('d-none');
            document.getElementById('eSectionB').classList.remove('d-none');
            document.getElementById('editMontant').value = btn.getAttribute('data-montant');
            document.getElementById('editDesc').value = btn.getAttribute('data-desc');
        }
    });

</script>
</body>
</html>