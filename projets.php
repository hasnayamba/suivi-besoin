<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ : Réservé à l'administrateur ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: login.php');
    exit();
}

// --- GESTION DES ACTIONS (AJOUT, MODIFICATION, SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. AJOUTER UN PROJET
    if (isset($_POST['add_projet'])) {
        $nom = trim($_POST['nom']);
        $description = trim($_POST['description']);
        $statut = trim($_POST['statut']);

        try {
            $stmt = $pdo->prepare("INSERT INTO projets (nom, description, statut) VALUES (?, ?, ?)");
            $stmt->execute([$nom, $description, $statut]);
            $_SESSION['success'] = "Le projet '$nom' a été créé avec succès.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur lors de l'ajout : " . $e->getMessage();
        }
    }
    
    // 2. MODIFIER UN PROJET
    elseif (isset($_POST['edit_projet'])) {
        $id = $_POST['projet_id'];
        $nom = trim($_POST['edit_nom']);
        $description = trim($_POST['edit_description']);
        $statut = trim($_POST['edit_statut']);
        
        try {
            $stmt = $pdo->prepare("UPDATE projets SET nom = ?, description = ?, statut = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $statut, $id]);
            $_SESSION['success'] = "Les informations du projet ont été mises à jour.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur lors de la modification : " . $e->getMessage();
        }
    }

    // 3. SUPPRIMER UN PROJET
    elseif (isset($_POST['delete_projet'])) {
        $id = $_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM projets WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Le projet a été supprimé des registres.";
        } catch (PDOException $e) {
            // Blocage si le projet est déjà utilisé
            $_SESSION['error'] = "Impossible de supprimer. Ce projet est lié à des demandes d'achat. Veuillez plutôt le modifier et le passer en statut 'Clôturé'.";
        }
    }
    
    header('Location: projets.php');
    exit();
}

// =====================================================================
// --- LOGIQUE DE RECHERCHE, FILTRAGE ET PAGINATION ---
// =====================================================================

$search = $_GET['search'] ?? '';
$filter_statut = $_GET['statut'] ?? '';

// Paramètres de pagination
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$conditions = [];
$params = [];

// Recherche (Nom ou Description)
if (!empty($search)) {
    $conditions[] = "(nom LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Filtre par statut
if (!empty($filter_statut)) {
    $conditions[] = "statut = ?";
    $params[] = $filter_statut;
}

$whereSql = '';
if (count($conditions) > 0) {
    $whereSql = "WHERE " . implode(' AND ', $conditions);
}

// Compter le total pour la pagination
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM projets $whereSql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Récupérer les données paginées
$sql = "SELECT * FROM projets $whereSql ORDER BY statut ASC, nom ASC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projets = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Projets - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-width: 260px; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 5px rgba(0,0,0,0.05); }
        .sidebar .p-4 { background-color: #212529; color: white; }
        .sidebar .nav-link { color: #495057; border-radius: 8px; padding: 10px 15px; margin-bottom: 5px; font-weight: 500; }
        .sidebar .nav-link:hover { background-color: #e9ecef; color: #212529; }
        .sidebar .nav-link.active { background-color: #ffc107; color: #212529; font-weight: bold; } /* Jaune pour les projets */
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <div class="collapse d-md-block" id="sidebarCollapse">
        <nav class="sidebar bg-white border-end">
            <div class="p-4 border-bottom">
                <h5 class="mb-1"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>Swisscontact</h5>
                <small class="opacity-75">Portail Administration</small>
            </div>
            <div class="p-3">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item"><a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-3"></i>Tableau de bord</a></li>
                    <li class="nav-item mt-3 mb-1 text-muted small text-uppercase fw-bold px-3">Gestion</li>
                    <li class="nav-item"><a class="nav-link" href="utilisateurs.php"><i class="bi bi-people me-3"></i>Utilisateurs</a></li>
                    <li class="nav-item"><a class="nav-link" href="fournisseurs.php"><i class="bi bi-truck me-3"></i>Fournisseurs</a></li>
                    <li class="nav-item"><a class="nav-link active" href="projets.php"><i class="bi bi-folder me-3"></i>Projets / Fonds</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse"><i class="bi bi-list"></i></button>
                <h3 class="h5 mb-0 fw-bold">Sources de Financement & Projets</h3>
            </div>
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#addProjetModal">
                <i class="bi bi-folder-plus me-2"></i>Nouveau Projet
            </button>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="alert alert-info py-2 small shadow-sm">
                <i class="bi bi-info-circle-fill me-2"></i> Les projets marqués comme <strong>"Clôturé"</strong> ne seront plus proposés aux demandeurs lors de la création d'un besoin, mais ils restent dans les archives.
            </div>

            <div class="card shadow-sm border-0 mt-3 mb-4">
                <div class="card-body bg-white rounded">
                    <form method="GET" action="projets.php" class="row g-3 align-items-center">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" placeholder="Rechercher par code, nom ou bailleur..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="statut" class="form-select" onchange="this.form.submit()">
                                <option value="">Tous les Statuts</option>
                                <option value="Actif" <?= ($filter_statut === 'Actif') ? 'selected' : '' ?>>Uniquement Actifs</option>
                                <option value="Clôturé" <?= ($filter_statut === 'Clôturé') ? 'selected' : '' ?>>Uniquement Clôturés</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-warning fw-bold">Filtrer</button>
                            <?php if(!empty($search) || !empty($filter_statut)): ?>
                                <a href="projets.php" class="btn btn-outline-secondary" title="Réinitialiser"><i class="bi bi-x-circle"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">Code / Nom du Projet</th>
                                    <th>Description / Bailleur</th>
                                    <th>Statut</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($projets)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted"><i class="bi bi-folder2-open fs-1 d-block mb-3"></i>Aucun projet trouvé.</td></tr>
                                <?php else: foreach ($projets as $p): 
                                    $is_actif = ($p['statut'] === 'Actif');
                                ?>
                                    <tr class="<?= !$is_actif ? 'bg-light text-muted' : '' ?>">
                                        <td class="ps-4 fw-bold <?= $is_actif ? 'text-dark' : 'text-muted' ?>">
                                            <i class="bi <?= $is_actif ? 'bi-folder-fill text-warning' : 'bi-folder-x' ?> me-2 fs-5"></i>
                                            <?= htmlspecialchars($p['nom']) ?>
                                        </td>
                                        <td><span class="small <?= !$is_actif ? 'text-muted' : '' ?>"><?= htmlspecialchars($p['description'] ?: 'Aucune description') ?></span></td>
                                        <td>
                                            <?php if ($is_actif): ?>
                                                <span class="badge bg-success">Actif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Clôturé</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-outline-dark me-1" title="Modifier"
                                                    data-bs-toggle="modal" data-bs-target="#editProjetModal"
                                                    data-id="<?= $p['id'] ?>"
                                                    data-nom="<?= htmlspecialchars($p['nom']) ?>"
                                                    data-description="<?= htmlspecialchars($p['description']) ?>"
                                                    data-statut="<?= htmlspecialchars($p['statut']) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Attention : Si ce projet est lié à des dépenses passées, il est préférable de le modifier en statut \'Clôturé\'. Supprimer ?');">
                                                <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                                <button type="submit" name="delete_projet" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Pagination" class="mt-4">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <?php $prev_params = array_merge($_GET, ['page' => $page - 1]); ?>
                            <a class="page-link text-dark" href="?<?= http_build_query($prev_params) ?>" tabindex="-1">Précédent</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                <?php $page_params = array_merge($_GET, ['page' => $i]); ?>
                                <a class="page-link <?= ($page == $i) ? 'bg-warning border-warning text-dark fw-bold' : 'text-dark' ?>" href="?<?= http_build_query($page_params) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <?php $next_params = array_merge($_GET, ['page' => $page + 1]); ?>
                            <a class="page-link text-dark" href="?<?= http_build_query($next_params) ?>">Suivant</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
            <div class="text-center mt-2 text-muted small">
                Affichage de <?= count($projets) ?> sur <?= $total_records ?> projets
            </div>

        </main>
    </div>
</div>

<div class="modal fade" id="addProjetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-folder-plus me-2"></i>Nouveau Projet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom complet ou Code du projet <span class="text-danger">*</span></label>
                        <input type="text" name="nom" class="form-control border-warning" required placeholder="Ex: Projet Santé Zinder (PSZ-2026)">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Description ou Bailleur de fonds (Optionnel)</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Ex: Financement USAID pour la période 2026-2028..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Statut initial <span class="text-danger">*</span></label>
                        <select name="statut" class="form-select" required>
                            <option value="Actif" selected>Actif (Disponible pour les achats)</option>
                            <option value="Clôturé">Clôturé (Bloqué pour les achats)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="add_projet" class="btn btn-warning fw-bold text-dark px-4">Créer le projet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editProjetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Modifier le Projet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="projet_id" id="edit_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom complet ou Code du projet <span class="text-danger">*</span></label>
                        <input type="text" name="edit_nom" id="edit_nom" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Description ou Bailleur</label>
                        <textarea name="edit_description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Statut <span class="text-danger">*</span></label>
                        <select name="edit_statut" id="edit_statut" class="form-select" required>
                            <option value="Actif">Actif (Disponible)</option>
                            <option value="Clôturé">Clôturé (Indisponible)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="edit_projet" class="btn btn-dark fw-bold px-4">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const editModal = document.getElementById('editProjetModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_nom').value = button.getAttribute('data-nom');
            document.getElementById('edit_description').value = button.getAttribute('data-description');
            
            const statut = button.getAttribute('data-statut');
            const statutSelect = document.getElementById('edit_statut');
            for(let i=0; i<statutSelect.options.length; i++) {
                if(statutSelect.options[i].value === statut) {
                    statutSelect.selectedIndex = i; break;
                }
            }
        });
    }
</script>
</body>
</html>