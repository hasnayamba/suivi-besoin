<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: login.php');
    exit();
}

// --- GESTION DES ACTIONS (AJOUT, MODIFICATION, SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. AJOUTER
    if (isset($_POST['add_fournisseur'])) {
        $nom = trim($_POST['nom']);
        $email = trim($_POST['email']);
        $telephone = trim($_POST['telephone']);
        $domaine = trim($_POST['domaine']);
        $localisation = trim($_POST['localisation']);

        try {
            $stmt = $pdo->prepare("INSERT INTO fournisseurs (nom, email, telephone, domaine, localisation) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $email, $telephone, $domaine, $localisation]);
            $_SESSION['success'] = "Le fournisseur '$nom' a été ajouté avec succès.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur lors de l'ajout : " . $e->getMessage();
        }
    }
    
    // 2. MODIFIER
    elseif (isset($_POST['edit_fournisseur'])) {
        $id = $_POST['fournisseur_id'];
        $nom = trim($_POST['edit_nom']);
        $email = trim($_POST['edit_email']);
        $telephone = trim($_POST['edit_telephone']);
        $domaine = trim($_POST['edit_domaine']);
        $localisation = trim($_POST['edit_localisation']);
        
        try {
            $stmt = $pdo->prepare("UPDATE fournisseurs SET nom = ?, email = ?, telephone = ?, domaine = ?, localisation = ? WHERE id = ?");
            $stmt->execute([$nom, $email, $telephone, $domaine, $localisation, $id]);
            $_SESSION['success'] = "Les informations du fournisseur ont été mises à jour.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur lors de la modification : " . $e->getMessage();
        }
    }

    // 3. SUPPRIMER
    elseif (isset($_POST['delete_fournisseur'])) {
        $id = $_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM fournisseurs WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Le fournisseur a été supprimé de la base.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Impossible de supprimer ce fournisseur car il est lié à des factures, marchés ou proformas existants.";
        }
    }
    
    header('Location: fournisseurs.php');
    exit();
}

// =====================================================================
// --- LOGIQUE DE RECHERCHE ET PAGINATION ---
// =====================================================================

$search = $_GET['search'] ?? '';

// Paramètres de pagination
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$conditions = [];
$params = [];

// Recherche globale (Nom, Email, Tel, Domaine, Localisation)
if (!empty($search)) {
    $conditions[] = "(nom LIKE ? OR email LIKE ? OR telephone LIKE ? OR domaine LIKE ? OR localisation LIKE ?)";
    $search_param = "%$search%";
    // On ajoute 5 fois le même paramètre car il y a 5 '?' dans la condition
    for ($i = 0; $i < 5; $i++) {
        $params[] = $search_param;
    }
}

$whereSql = '';
if (count($conditions) > 0) {
    $whereSql = "WHERE " . implode(' AND ', $conditions);
}

// Compter le total pour la pagination
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM fournisseurs $whereSql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Récupérer les données de la page actuelle
$sql = "SELECT * FROM fournisseurs $whereSql ORDER BY nom ASC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fournisseurs = $stmt->fetchAll();

$domaines_frequents = ['Informatique & High-Tech', 'Fournitures de Bureau', 'Mobilier', 'BTP & Construction', 'Services / Consultance', 'Logistique & Transport', 'Imprimerie & Communication'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Fournisseurs - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-width: 260px; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 5px rgba(0,0,0,0.05); }
        .sidebar .p-4 { background-color: #212529; color: white; }
        .sidebar .nav-link { color: #495057; border-radius: 8px; padding: 10px 15px; margin-bottom: 5px; font-weight: 500; }
        .sidebar .nav-link:hover { background-color: #e9ecef; color: #212529; }
        .sidebar .nav-link.active { background-color: #198754; color: white; }
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
                    <li class="nav-item"><a class="nav-link active" href="fournisseurs.php"><i class="bi bi-truck me-3"></i>Fournisseurs</a></li>
                    <li class="nav-item"><a class="nav-link" href="projets.php"><i class="bi bi-folder me-3"></i>Projets / Fonds</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse"><i class="bi bi-list"></i></button>
                <h3 class="h5 mb-0 fw-bold">Gestion de la Base Fournisseurs</h3>
            </div>
            <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addFournisseurModal">
                <i class="bi bi-plus-circle-fill me-2"></i>Nouveau Fournisseur
            </button>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body bg-white rounded">
                    <form method="GET" action="fournisseurs.php" class="row g-3 align-items-center">
                        <div class="col-md-9">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control border-start-0" placeholder="Rechercher une entreprise, un email, un domaine ou une ville..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-3 d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-success px-4 fw-bold">Chercher</button>
                            <?php if(!empty($search)): ?>
                                <a href="fournisseurs.php" class="btn btn-outline-secondary" title="Réinitialiser"><i class="bi bi-x-circle"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">Entreprise / Nom</th>
                                    <th>Contacts</th>
                                    <th>Domaine d'activité</th>
                                    <th>Localisation</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fournisseurs)): ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-shop fs-1 d-block mb-3"></i>Aucun fournisseur trouvé.</td></tr>
                                <?php else: foreach ($fournisseurs as $f): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark"><i class="bi bi-building text-success me-2"></i><?= htmlspecialchars($f['nom']) ?></td>
                                        <td>
                                            <div class="small">
                                                <i class="bi bi-envelope me-1 text-muted"></i> <?= htmlspecialchars($f['email']) ?><br>
                                                <i class="bi bi-telephone me-1 text-muted"></i> <?= htmlspecialchars($f['telephone'] ?: 'Non renseigné') ?>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($f['domaine']) ?></span></td>
                                        <td>
                                            <?php if ($f['localisation']): ?>
                                                <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($f['localisation']) ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Non renseignée</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-outline-success me-1" title="Modifier"
                                                    data-bs-toggle="modal" data-bs-target="#editFournisseurModal"
                                                    data-id="<?= $f['id'] ?>" data-nom="<?= htmlspecialchars($f['nom']) ?>"
                                                    data-email="<?= htmlspecialchars($f['email']) ?>" data-telephone="<?= htmlspecialchars($f['telephone']) ?>"
                                                    data-domaine="<?= htmlspecialchars($f['domaine']) ?>" data-localisation="<?= htmlspecialchars($f['localisation']) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce fournisseur ?');">
                                                <input type="hidden" name="delete_id" value="<?= $f['id'] ?>">
                                                <button type="submit" name="delete_fournisseur" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
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
                <nav aria-label="Pagination">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <?php $prev_params = array_merge($_GET, ['page' => $page - 1]); ?>
                            <a class="page-link text-success" href="?<?= http_build_query($prev_params) ?>" tabindex="-1">Précédent</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                <?php $page_params = array_merge($_GET, ['page' => $i]); ?>
                                <a class="page-link <?= ($page == $i) ? 'bg-success border-success' : 'text-success' ?>" href="?<?= http_build_query($page_params) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <?php $next_params = array_merge($_GET, ['page' => $page + 1]); ?>
                            <a class="page-link text-success" href="?<?= http_build_query($next_params) ?>">Suivant</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
            <div class="text-center mt-2 text-muted small">
                Affichage de <?= count($fournisseurs) ?> sur <?= $total_records ?> fournisseurs
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="addFournisseurModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-success">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-shop me-2"></i>Ajouter un Fournisseur</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom de l'entreprise ou du prestataire <span class="text-danger">*</span></label>
                        <input type="text" name="nom" class="form-control border-success" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Adresse Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Numéro de Téléphone</label>
                            <input type="text" name="telephone" class="form-control">
                        </div>
                    </div>
                    <div class="row border-top pt-3 mt-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Domaine d'activité <span class="text-danger">*</span></label>
                            <input type="text" name="domaine" class="form-control" list="liste_domaines" required>
                            <datalist id="liste_domaines">
                                <?php foreach ($domaines_frequents as $d): ?>
                                    <option value="<?= htmlspecialchars($d) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Localisation / Adresse</label>
                            <input type="text" name="localisation" class="form-control" placeholder="Ex: Niamey, Quartier Plateau...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="add_fournisseur" class="btn btn-success fw-bold px-4">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editFournisseurModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Modifier le Fournisseur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="fournisseur_id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom de l'entreprise <span class="text-danger">*</span></label>
                        <input type="text" name="edit_nom" id="edit_nom" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Adresse Email <span class="text-danger">*</span></label>
                            <input type="email" name="edit_email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Numéro de Téléphone</label>
                            <input type="text" name="edit_telephone" id="edit_telephone" class="form-control">
                        </div>
                    </div>
                    <div class="row border-top pt-3 mt-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Domaine d'activité <span class="text-danger">*</span></label>
                            <input type="text" name="edit_domaine" id="edit_domaine" class="form-control" list="liste_domaines" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Localisation / Adresse</label>
                            <input type="text" name="edit_localisation" id="edit_localisation" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="edit_fournisseur" class="btn btn-success fw-bold px-4">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const editModal = document.getElementById('editFournisseurModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_nom').value = button.getAttribute('data-nom');
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            document.getElementById('edit_telephone').value = button.getAttribute('data-telephone');
            document.getElementById('edit_domaine').value = button.getAttribute('data-domaine');
            document.getElementById('edit_localisation').value = button.getAttribute('data-localisation');
        });
    }
</script>
</body>
</html>