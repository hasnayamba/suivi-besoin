<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: login.php');
    exit();
}

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Administrateur';

// --- GESTION DES ACTIONS (AJOUT, MODIFICATION, SUPPRESSION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. AJOUTER UN UTILISATEUR
    if (isset($_POST['add_user'])) {
        $nom = trim($_POST['nom']);
        $email = trim($_POST['email']);
        $mot_de_passe = password_hash(trim($_POST['mot_de_passe']), PASSWORD_DEFAULT);
        $role = trim($_POST['role']);
        $antenne = trim($_POST['antenne']);

        // SÉCURITÉ : Interdiction stricte de créer un Admin
        if ($role === 'Admin') {
            $_SESSION['error'] = "Sécurité : La création de nouveaux comptes Administrateur est bloquée.";
        } else {
            try {
                $stmt_check = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
                $stmt_check->execute([$email]);
                if ($stmt_check->rowCount() > 0) {
                    $_SESSION['error'] = "Cet email est déjà utilisé par un autre compte.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role, antenne) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nom, $email, $mot_de_passe, $role, $antenne]);
                    $_SESSION['success'] = "Le compte utilisateur a été créé avec succès.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Erreur lors de l'ajout : " . $e->getMessage();
            }
        }
    }
    
    // 2. MODIFIER UN UTILISATEUR
    elseif (isset($_POST['edit_user'])) {
        $id = $_POST['user_id'];
        $nom = trim($_POST['edit_nom']);
        $email = trim($_POST['edit_email']);
        $role = trim($_POST['edit_role']);
        $antenne = trim($_POST['edit_antenne']);
        
        // SÉCURITÉ : Vérifier si on essaie de promouvoir quelqu'un en Admin illégalement
        $stmt_old_role = $pdo->prepare("SELECT role FROM utilisateurs WHERE id = ?");
        $stmt_old_role->execute([$id]);
        $ancien_role = $stmt_old_role->fetchColumn();

        if ($role === 'Admin' && $ancien_role !== 'Admin') {
            $_SESSION['error'] = "Sécurité : Vous ne pouvez pas promouvoir un utilisateur au rang d'Administrateur.";
        } else {
            try {
                if (!empty($_POST['edit_mot_de_passe'])) {
                    $mot_de_passe = password_hash(trim($_POST['edit_mot_de_passe']), PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, email = ?, mot_de_passe = ?, role = ?, antenne = ? WHERE id = ?");
                    $stmt->execute([$nom, $email, $mot_de_passe, $role, $antenne, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, email = ?, role = ?, antenne = ? WHERE id = ?");
                    $stmt->execute([$nom, $email, $role, $antenne, $id]);
                }
                $_SESSION['success'] = "Les informations ont été mises à jour.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Erreur lors de la modification : " . $e->getMessage();
            }
        }
    }

    // 3. SUPPRIMER UN UTILISATEUR
    elseif (isset($_POST['delete_user'])) {
        $id = $_POST['delete_id'];
        
        // Sécurité : Empêcher l'admin de se supprimer lui-même (Double vérification)
        if ($id == $_SESSION['user_id']) {
            $_SESSION['error'] = "Vous ne pouvez pas supprimer votre propre compte.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success'] = "L'utilisateur a été supprimé.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Impossible de supprimer cet utilisateur (lié à des besoins ou marchés existants).";
            }
        }
    }
    header('Location: utilisateurs.php');
    exit();
}

// =====================================================================
// --- LOGIQUE DE FILTRAGE ET PAGINATION ---
// =====================================================================

$search = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_antenne = $_GET['antenne'] ?? '';

$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(nom LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($filter_role)) {
    $conditions[] = "role = ?";
    $params[] = $filter_role;
}
if (!empty($filter_antenne)) {
    $conditions[] = "antenne = ?";
    $params[] = $filter_antenne;
}

$whereSql = '';
if (count($conditions) > 0) {
    $whereSql = "WHERE " . implode(' AND ', $conditions);
}

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs $whereSql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

$sql = "SELECT * FROM utilisateurs $whereSql ORDER BY nom ASC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll();

$liste_antennes = ['Niamey', 'Maradi', 'Zinder', 'Tahoua', 'Agadez', 'Dosso', 'Diffa', 'Tillabéri', 'Siège / Autre'];

// MISE À JOUR : Le rôle Demandeur devient Administration
$liste_roles = [
    'Administration' => 'Administration (Initiateur)',
    'Logisticien' => 'Logistique',
    'Comptable' => 'Comptabilité',
    'Finance' => 'Finance',
    'Admin' => 'Administrateur' // Conservé uniquement pour affichage
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-width: 260px; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 5px rgba(0,0,0,0.05); }
        .sidebar .p-4 { background-color: #212529; color: white; }
        .sidebar .nav-link { color: #495057; border-radius: 8px; padding: 10px 15px; margin-bottom: 5px; font-weight: 500; }
        .sidebar .nav-link:hover { background-color: #e9ecef; color: #212529; }
        .sidebar .nav-link.active { background-color: #0d6efd; color: white; }
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
                    <li class="nav-item"><a class="nav-link active" href="utilisateurs.php"><i class="bi bi-people me-3"></i>Utilisateurs</a></li>
                    <li class="nav-item"><a class="nav-link" href="fournisseurs.php"><i class="bi bi-truck me-3"></i>Fournisseurs</a></li>
                    <li class="nav-item"><a class="nav-link" href="projets.php"><i class="bi bi-folder me-3"></i>Projets / Fonds</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse"><i class="bi bi-list"></i></button>
                <h3 class="h5 mb-0 fw-bold">Gestion des Utilisateurs</h3>
            </div>
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-person-plus-fill me-2"></i>Nouveau Compte
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
                    <form method="GET" action="utilisateurs.php" class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher par nom ou email..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="role" class="form-select" onchange="this.form.submit()">
                                <option value="">Tous les Rôles</option>
                                <?php foreach ($liste_roles as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= ($filter_role === (string)$val) ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="antenne" class="form-select" onchange="this.form.submit()">
                                <option value="">Toutes les Antennes</option>
                                <?php foreach ($liste_antennes as $ant): ?>
                                    <option value="<?= $ant ?>" <?= ($filter_antenne === $ant) ? 'selected' : '' ?>><?= $ant ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filtrer</button>
                            <?php if(!empty($search) || !empty($filter_role) || !empty($filter_antenne)): ?>
                                <a href="utilisateurs.php" class="btn btn-outline-secondary" title="Réinitialiser"><i class="bi bi-x-circle"></i></a>
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
                                    <th class="ps-4">Nom Complet</th>
                                    <th>Email</th>
                                    <th>Rôle</th>
                                    <th>Antenne</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($utilisateurs)): ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-search fs-1 d-block mb-3"></i>Aucun utilisateur trouvé pour ces critères.</td></tr>
                                <?php else: foreach ($utilisateurs as $user): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark"><i class="bi bi-person-circle text-secondary me-2 fs-5"></i><?= htmlspecialchars($user['nom']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td>
                                            <?php 
                                                $r = strtolower($user['role']);
                                                $badge = 'bg-secondary';
                                                if ($r == 'admin') $badge = 'bg-dark';
                                                elseif ($r == 'logisticien') $badge = 'bg-primary';
                                                elseif ($r == 'comptable' || $r == 'finance') $badge = 'bg-success';
                                                elseif ($r == 'administration') $badge = 'bg-info text-dark';
                                            ?>
                                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($user['role']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($user['antenne']): ?>
                                                <i class="bi bi-geo-alt-fill text-danger me-1 small"></i><?= htmlspecialchars($user['antenne']) ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Non définie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-outline-primary me-1" title="Modifier"
                                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                    data-id="<?= $user['id'] ?>" data-nom="<?= htmlspecialchars($user['nom']) ?>"
                                                    data-email="<?= htmlspecialchars($user['email']) ?>" data-role="<?= htmlspecialchars($user['role']) ?>"
                                                    data-antenne="<?= htmlspecialchars($user['antenne']) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
                                                <input type="hidden" name="delete_id" value="<?= $user['id'] ?>">
                                                <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
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
                            <a class="page-link" href="?<?= http_build_query($prev_params) ?>" tabindex="-1">Précédent</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                <?php $page_params = array_merge($_GET, ['page' => $i]); ?>
                                <a class="page-link" href="?<?= http_build_query($page_params) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <?php $next_params = array_merge($_GET, ['page' => $page + 1]); ?>
                            <a class="page-link" href="?<?= http_build_query($next_params) ?>">Suivant</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
            <div class="text-center mt-2 text-muted small">
                Affichage de <?= count($utilisateurs) ?> sur <?= $total_records ?> utilisateurs
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-primary">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Créer un compte</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom complet <span class="text-danger">*</span></label>
                        <input type="text" name="nom" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Adresse Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Mot de passe provisoire <span class="text-danger">*</span></label>
                        <input type="password" name="mot_de_passe" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Rôle <span class="text-danger">*</span></label>
                            <select name="role" class="form-select border-primary" required>
                                <option value="" disabled selected>-- Choisir --</option>
                                <?php foreach ($liste_roles as $val => $label): ?>
                                    <?php if ($val !== 'Admin'): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Antenne <span class="text-danger">*</span></label>
                            <select name="antenne" class="form-select border-danger" required>
                                <option value="" disabled selected>-- Localisation --</option>
                                <?php foreach ($liste_antennes as $ant): ?>
                                    <option value="<?= $ant ?>"><?= $ant ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="add_user" class="btn btn-primary fw-bold">Créer le compte</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Modifier le compte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="edit_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nom complet <span class="text-danger">*</span></label>
                        <input type="text" name="edit_nom" id="edit_nom" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Adresse Email <span class="text-danger">*</span></label>
                        <input type="email" name="edit_email" id="edit_email" class="form-control" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Rôle <span class="text-danger">*</span></label>
                            <select name="edit_role" id="edit_role" class="form-select" required>
                                <?php foreach ($liste_roles as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Antenne <span class="text-danger">*</span></label>
                            <select name="edit_antenne" id="edit_antenne" class="form-select" required>
                                <?php foreach ($liste_antennes as $ant): ?>
                                    <option value="<?= $ant ?>"><?= $ant ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 border-top pt-3">
                        <label class="form-label fw-bold small text-muted">Réinitialiser le mot de passe (Optionnel)</label>
                        <input type="password" name="edit_mot_de_passe" class="form-control" placeholder="Laissez vide pour conserver l'actuel">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="edit_user" class="btn btn-primary fw-bold">Enregistrer les modifications</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const editModal = document.getElementById('editUserModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_nom').value = button.getAttribute('data-nom');
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            
            const role = button.getAttribute('data-role');
            const antenne = button.getAttribute('data-antenne');
            
            let roleSelect = document.getElementById('edit_role');
            for(let i=0; i<roleSelect.options.length; i++) {
                // Désactiver l'option Admin si l'utilisateur n'est pas déjà un Admin
                if (roleSelect.options[i].value === 'Admin') {
                    roleSelect.options[i].disabled = (role !== 'Admin');
                }
                
                if(roleSelect.options[i].value === role) {
                    roleSelect.selectedIndex = i;
                }
            }

            let antenneSelect = document.getElementById('edit_antenne');
            for(let i=0; i<antenneSelect.options.length; i++) {
                if(antenneSelect.options[i].value === antenne) {
                    antenneSelect.selectedIndex = i; break;
                }
            }
        });
    }
</script>
</body>
</html>