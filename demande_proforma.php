
<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}
$utilisateur_nom = $_SESSION['user_nom'];

// --- FONCTION POUR GÉNÉRER UN ID ---
function generateProformaId() {
    return 'DP' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
}

// --- GESTION DE L'AJOUT D'UNE DEMANDE PROFORMA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proforma'])) {
    $besoin_id = trim($_POST['proformaBesoinId']);
    $delai_reponse = trim($_POST['proformaDelai']);
    $fichier_nom = null;

    if (empty($besoin_id) || empty($delai_reponse)) {
        $_SESSION['error'] = "Veuillez sélectionner un besoin et spécifier un délai.";
    } else {
        if (isset($_FILES['proformaFichier']) && $_FILES['proformaFichier']['error'] === UPLOAD_ERR_OK) {
            // ... (logique de gestion du fichier uploadé)
        }

        if (!isset($_SESSION['error'])) {
            try {
                $stmtBesoin = $pdo->prepare("SELECT titre FROM besoins WHERE id = :id");
                $stmtBesoin->execute([':id' => $besoin_id]);
                $titre_besoin = $stmtBesoin->fetchColumn();
                
                $id = generateProformaId();
                $sql = "INSERT INTO demandes_proforma (id, titre_besoin, emetteur, date_emission, delai_reponse, statut, fichier, besoin_id)
                        VALUES (:id, :titre_besoin, :emetteur, CURDATE(), :delai_reponse, 'En attente', :fichier, :besoin_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id' => $id, ':titre_besoin' => $titre_besoin, ':emetteur' => $utilisateur_nom,
                    ':delai_reponse' => $delai_reponse, ':fichier' => $fichier_nom, ':besoin_id' => $besoin_id
                ]);

                $stmtUpdate = $pdo->prepare("UPDATE besoins SET statut = 'En cours de proforma' WHERE id = :id");
                $stmtUpdate->execute([':id' => $besoin_id]);
                
                $_SESSION['success'] = "La demande de proforma a été créée avec succès.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
            }
        }
    }
    header('Location: demande_proforma.php');
    exit();
}

// --- LOGIQUE DE FILTRE ET RECHERCHE ---
$filter_status = $_GET['statut'] ?? '';
$search_query = $_GET['recherche'] ?? '';

function get_demandes_proforma($pdo, $status = '', $search = '') {
    try {
        $sql = "SELECT * FROM demandes_proforma";
        $conditions = [];
        $params = [];

        if (!empty($status)) {
            $conditions[] = "statut = ?";
            $params[] = $status;
        }
        if (!empty($search)) {
            $conditions[] = "titre_besoin LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY date_emission DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur de chargement des demandes.";
        return [];
    }
}

function get_demande_status_badge($statut) {
    $map = ['En attente' => 'bg-secondary', 'Réponses en cours' => 'bg-warning text-dark', 'Validé' => 'bg-success'];
    $class = $map[$statut] ?? 'bg-light text-dark';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}

// --- Exécution de la récupération des données ---
$demandes_proforma = get_demandes_proforma($pdo, $filter_status, $search_query);
$besoins_disponibles = $pdo->query("SELECT id, titre FROM besoins WHERE statut = 'Validé'")->fetchAll();
$all_status = $pdo->query("SELECT DISTINCT statut FROM demandes_proforma WHERE statut IS NOT NULL ORDER BY statut")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes Proforma - Logisticien</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <?php include 'header.php'; ?>

        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Demandes Proforma</h2>
                        <p class="text-muted mb-0 small">Suivi des demandes en cours et archivées</p>
                    </div>
                       <a href="besoins_logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
                </div>
            </header>
            
            <main class="flex-fill overflow-auto p-4">
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                
                <div class="d-flex justify-content-end mb-4">
                    <button class="btn btn-primary d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#newProformaModal"><i class="bi bi-plus-circle me-2"></i>Lancer une demande</button>
                </div>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="demande_proforma.php" class="row g-3 align-items-center">
                            <div class="col-md-6"><input type="text" name="recherche" class="form-control" placeholder="Rechercher par titre de besoin..." value="<?= htmlspecialchars($search_query) ?>"></div>
                            <div class="col-md-4">
                                <select name="statut" class="form-select">
                                    <option value="">-- Filtrer par statut --</option>
                                    <?php foreach ($all_status as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= ($filter_status === $status) ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-grid gap-2 d-md-flex"><button type="submit" class="btn btn-primary">Filtrer</button><a href="demande_proforma.php" class="btn btn-outline-secondary">Reset</a></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Liste des demandes</h5></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Besoin Associé</th><th>Émetteur</th><th>Date d'émission</th> <th>Délai</th><th>Statut</th><th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($demandes_proforma)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">Aucune demande de proforma ne correspond à vos critères.</td></tr>
                                    <?php else: foreach ($demandes_proforma as $demande): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($demande['id']) ?></code></td>
                                            <td><?= htmlspecialchars($demande['titre_besoin']) ?></td>
                                            <td><?= htmlspecialchars($demande['emetteur']) ?></td>
                                            <td><?= date('d/m/Y', strtotime($demande['date_emission'])) ?></td>
                                             <td><?= date('d/m/Y', strtotime($demande['delai_reponse'])) ?></td>
                                            <td><?= get_demande_status_badge($demande['statut']) ?></td>
                                            <td class="text-end">
                                                <?php if (!empty($demande['fichier'])): ?>
                                                    <a href="uploads/<?= htmlspecialchars($demande['fichier']) ?>" class="btn btn-sm btn-outline-secondary" title="Télécharger la pièce jointe" download>
                                                        <i class="bi bi-paperclip"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <div class="btn-group" role="group">
                                                    <a href="gerer_reponses.php?id=<?= htmlspecialchars($demande['id']) ?>" class="btn btn-primary btn-sm"><i class="bi bi-journal-plus me-1"></i> Gérer</a>
                                                    <?php if ($demande['statut'] === 'En attente' || $demande['statut'] === 'Réponses en cours'): ?>
                                                        <a href="modifier_demande.php?id=<?= htmlspecialchars($demande['id']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <div class="modal fade" id="newProformaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Lancer une demande proforma</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form action="demande_proforma.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="proformaBesoinId" class="form-label">Associer à un besoin <span class="text-danger">*</span></label>
                            <select class="form-select" id="proformaBesoinId" name="proformaBesoinId" required>
                                <option value="" disabled selected>-- Sélectionnez un besoin validé --</option>
                                <?php foreach ($besoins_disponibles as $besoin): ?>
                                    <option value="<?= htmlspecialchars($besoin['id']) ?>"><?= htmlspecialchars($besoin['titre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><label for="proformaDelai" class="form-label">Délai de réponse <span class="text-danger">*</span></label><input type="date" class="form-control" id="proformaDelai" name="proformaDelai" required min="<?= date('Y-m-d') ?>"></div>
                        <div class="mb-3"><label for="proformaFichier" class="form-label">Joindre un document (Optionnel)</label><input class="form-control" type="file" id="proformaFichier" name="proformaFichier"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="submit_proforma" class="btn btn-primary">Lancer la demande</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>