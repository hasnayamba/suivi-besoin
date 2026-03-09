<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>suivie besoins - PV & Commandes</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <link rel="stylesheet" href="styles.css">
</head>
<body class="bg-light">

    <div class="d-flex vh-100">
        <nav class="sidebar bg-white border-end" style="width: 260px;">
            <div class="p-4 border-bottom">
                <h5 class="mb-1">Suivie des Besoins</h5>
            </div>
            
            <div class="p-3">
                 <ul class="nav nav-pills flex-column">
                    <li class="nav-item mb-1">
                        <a class="nav-link active d-flex align-items-center" href="logisticien.php">
                            <i class="bi bi-person-workspace me-2"></i>
                            Tableau de Bord
                                    <?php
                                    include 'db_connect.php';
                                    $sql = "SELECT id, titre, fournisseur, montant, date_debut, date_fin, statut FROM marches ORDER BY date_debut DESC";
                                    $result = $pdo->query($sql);
                                    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                                        echo '<tr>';
                                        echo '<td><code>' . htmlspecialchars($row['id']) . '</code></td>';
                                        echo '<td>' . htmlspecialchars($row['titre']) . '</td>';
                                        echo '<td>' . htmlspecialchars($row['fournisseur']) . '</td>';
                                        echo '<td>' . htmlspecialchars(number_format($row['montant'], 0, ',', ' ')) . ' cfa</td>';
                                        echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($row['date_debut']))) . '</td>';
                                        echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($row['date_fin']))) . '</td>';
                                        $badge = 'bg-secondary';
                                        if ($row['statut'] == 'Finalisé') $badge = 'bg-success';
                                        elseif ($row['statut'] == 'En cours') $badge = 'bg-primary';
                                        elseif ($row['statut'] == 'Annulé') $badge = 'bg-danger';
                                        elseif ($row['statut'] == 'En attente') $badge = 'bg-warning';
                                        echo '<td><span class="badge ' . $badge . '">' . htmlspecialchars($row['statut']) . '</span></td>';
                                        echo '<td class="text-end">';
                                        echo '<div class="dropdown">';
                                        echo '<button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown">';
                                        echo '<i class="bi bi-three-dots"></i>';
                                        echo '</button>';
                                        echo '<ul class="dropdown-menu dropdown-menu-end">';
                                        echo '<li><a class="dropdown-item" href="#"><i class="bi bi-eye me-2"></i>Voir détails</a></li>';
                                        echo '<li><a class="dropdown-item" href="#"><i class="bi bi-download me-2"></i>Télécharger</a></li>';
                                        echo '</ul>';
                                        echo '</div>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                    ?>
                                <i class="bi bi-file-earmark-check me-2"></i>
                                PV & Commandes
                            </a>
                        </li>
                         <li class="nav-item mb-1">
                            <a class="nav-link d-flex align-items-center" href="workflow.php">
                                <i class="bi bi-file-earmark-check me-2"></i>
                                workflow
                            </a>
                        </li>
                        <li class="nav-item mb-1">
                            <a class="nav-link d-flex align-items-center" href="historique.php">
                                <i class="bi bi-archive me-2"></i>
                                Historique
                            </a>
                        </li>
                    </ul>
                </div>
               
        </nav>

        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">PV & Commandes</h2>
                        <p class="text-muted mb-0 small">Gestion et suivi des procès-verbaux et bons de commande</p>
                    </div>
                    
                    <div class="d-flex align-items-center gap-3">
                        <div class="position-relative">
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                            <input type="text" class="form-control ps-5" placeholder="Rechercher un PV ou une commande..." style="width: 250px;">
                        </div>
                        
                        <div class="dropdown">
                            <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">3</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;">
                                <li class="dropdown-header d-flex justify-content-between">
                                    <span>Notifications</span>
                                    <small><a href="#" class="text-decoration-none">Tout marquer lu</a></small>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li class="px-3 py-2 bg-light bg-opacity-50">
                                    <div class="d-flex">
                                        <i class="bi bi-info-circle text-primary me-2 mt-1"></i>
                                        <div class="flex-fill">
                                            <div class="fw-medium small">Nouveau besoin soumis</div>
                                            <div class="text-muted small">Un nouveau besoin "Équipements informatiques" a été soumis</div>
                                            <div class="text-muted small">Il y a 30min</div>
                                        </div>
                                    </div>
                                </li>
                                <li class="px-3 py-2">
                                    <div class="d-flex">
                                        <i class="bi bi-clock text-warning me-2 mt-1"></i>
                                        <div class="flex-fill">
                                            <div class="fw-medium small">Délai proforma expiré</div>
                                            <div class="text-muted small">Le délai pour la réception expire dans 2 heures</div>
                                            <div class="text-muted small">Il y a 2h</div>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="dropdown">
                            <button class="btn btn-light" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li class="px-3 py-2">
                                    <div class="fw-medium">Souley</div>
                                    <div class="small text-muted">souley@swisscontact.org</div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li class="dropdown-header small text-muted">Parametres</li>
                                <li><a class="dropdown-item" href="deconnexion.php" >Deconnexion</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-fill overflow-auto p-4">
                 <div class="d-flex justify-content-end mb-4">
                    <button class="btn btn-primary d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal">
                        <i class="bi bi-file-earmark-plus me-2"></i>
                        Ajouter un PV/Commande
                    </button>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Liste des PV & Commandes</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>