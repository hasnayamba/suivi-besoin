<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET RÉCUPÉRATION DES DONNÉES ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// Récupérer l'ID de la demande de proforma depuis l'URL et valider
$demande_id = $_GET['id'] ?? null;
if (!$demande_id) {
    $_SESSION['error'] = "Aucun identifiant de demande spécifié.";
    header('Location: demande_proforma.php');
    exit();
}

// --- GESTION DE L'AJOUT D'UNE PROFORMA REÇUE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proforma_recue'])) {
    $fournisseur = trim($_POST['fournisseur']);
    $montant = trim($_POST['montant']);
    $delai = trim($_POST['delai']);
    $date_reception = trim($_POST['date_reception']);
    $fichier_nom = null;

    if (empty($fournisseur) || empty($montant) || empty($date_reception)) {
        $_SESSION['error'] = "Veuillez remplir tous les champs obligatoires.";
    } else {
        if (isset($_FILES['fichier_proforma']) && $_FILES['fichier_proforma']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['fichier_proforma']['tmp_name'];
            $fileName = basename($_FILES['fichier_proforma']['name']);
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $uploadFileDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
            
            $newFileName = 'PROFORMA_' . time() . '.' . $fileExtension;
            $destPath = $uploadFileDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $fichier_nom = $newFileName;
            } else {
                $_SESSION['error'] = "Erreur lors de la sauvegarde du fichier.";
            }
        }

        if (!isset($_SESSION['error'])) {
            try {
                $sql = "INSERT INTO proformas_recus (demande_proforma_id, fournisseur, montant, delai, date_reception, fichier, statut) 
                        VALUES (:demande_id, :fournisseur, :montant, :delai, :date_reception, :fichier, 'En attente')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':demande_id' => $demande_id,
                    ':fournisseur' => $fournisseur,
                    ':montant' => $montant,
                    ':delai' => $delai,
                    ':date_reception' => $date_reception,
                    ':fichier' => $fichier_nom
                ]);

                // Mise à jour du statut de la demande parente
                $stmt_update_demande = $pdo->prepare("UPDATE demandes_proforma SET statut = 'Réponses en cours' WHERE id = :demande_id AND statut = 'En attente'");
                $stmt_update_demande->execute([':demande_id' => $demande_id]);

                $_SESSION['success'] = "Proforma du fournisseur '$fournisseur' ajoutée avec succès.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Erreur SQL: " . $e->getMessage();
            }
        }
    }
    header('Location: gerer_reponses.php?id=' . $demande_id);
    exit();
}


// --- RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE ---
$stmt_demande = $pdo->prepare("SELECT * FROM demandes_proforma WHERE id = :id");
$stmt_demande->execute([':id' => $demande_id]);
$demande = $stmt_demande->fetch();

if (!$demande) {
    $_SESSION['error'] = "La demande de proforma est introuvable.";
    header('Location: demande_proforma.php');
    exit();
}

$stmt_reponses = $pdo->prepare("SELECT * FROM proformas_recus WHERE demande_proforma_id = :id ORDER BY date_reception DESC");
$stmt_reponses->execute([':id' => $demande_id]);
$reponses = $stmt_reponses->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer les réponses Proforma</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <?php
    include 'header.php';
    ?>
        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Gestion des Réponses Proforma</h2>
                        <p class="text-muted mb-0 small">Demande: <?= htmlspecialchars($demande['titre_besoin']) ?> (<code><?= htmlspecialchars($demande['id']) ?></code>)</p>
                    </div>
                    <a href="demande_proforma.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
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
                    <button class="btn btn-primary d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#addProformaRecueModal" <?= ($demande['statut'] !== 'En attente' && $demande['statut'] !== 'Réponses en cours') ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-circle me-2"></i>Ajouter une proforma reçue
                    </button>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Liste des proformas reçues</h5></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Fournisseur</th>
                                        <th>Montant</th>
                                        <th>Délai Livraison</th>
                                        <th>Statut</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reponses)): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Aucune proforma reçue pour cette demande.</td></tr>
                                    <?php else: foreach ($reponses as $reponse): 
                                        $row_class = '';
                                        if ($reponse['statut'] == 'Validé') $row_class = 'table-success';
                                        if ($reponse['statut'] == 'Rejeté') $row_class = 'text-muted opacity-50';
                                    ?>
                                        <tr class="<?= $row_class ?>">
                                            <td><?= htmlspecialchars($reponse['fournisseur']) ?></td>
                                            <td><strong><?= number_format($reponse['montant'], 0, ',', ' ') . ' cfa' ?></strong></td>
                                            <td><?= htmlspecialchars($reponse['delai']) ?></td>
                                            <td>
                                                <?php if($reponse['statut'] == 'Validé'): ?>
                                                    <span class="badge bg-success">Validé</span>
                                                <?php elseif($reponse['statut'] == 'Rejeté'): ?>
                                                    <span class="badge bg-danger">Rejeté</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">En attente</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!empty($reponse['fichier'])): ?>
                                                    <a href="uploads/<?= htmlspecialchars($reponse['fichier']) ?>" class="btn btn-sm btn-outline-secondary" title="Télécharger" download><i class="bi bi-download"></i></a>
                                                <?php endif; ?>

                                                <?php if (($demande['statut'] == 'Réponses en cours' || $demande['statut'] == 'En attente') && $reponse['statut'] == 'En attente'): ?>
                                                    <button type="button" class="btn btn-sm btn-success valider-offre-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalValiderOffre"
                                                            data-reponse-id="<?= $reponse['id'] ?>"
                                                            data-fournisseur="<?= htmlspecialchars($reponse['fournisseur']) ?>"
                                                            data-montant="<?= number_format($reponse['montant'], 0, ',', ' ') . ' cfa' ?>">
                                                        <i class="bi bi-check-circle"></i> Valider
                                                    </button>
                                                <?php endif; ?>
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
    
    <div class="modal fade" id="addProformaRecueModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter une proforma reçue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="gerer_reponses.php?id=<?= htmlspecialchars($demande_id) ?>" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3"><label for="fournisseur" class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label><input type="text" class="form-control" id="fournisseur" name="fournisseur" required></div>
                        <div class="mb-3"><label for="montant" class="form-label">Montant Total (cfa) <span class="text-danger">*</span></label><input type="number" class="form-control" id="montant" name="montant" required></div>
                        <div class="mb-3"><label for="delai" class="form-label">Délai de livraison (ex: 15 jours)</label><input type="text" class="form-control" id="delai" name="delai"></div>
                        <div class="mb-3"><label for="date_reception" class="form-label">Date de réception <span class="text-danger">*</span></label><input type="date" class="form-control" id="date_reception" name="date_reception" required></div>
                        <div class="mb-3"><label for="fichier_proforma" class="form-label">Fichier de la proforma</label><input class="form-control" type="file" id="fichier_proforma" name="fichier_proforma"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="submit_proforma_recue" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalValiderOffre" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Valider l'offre et créer le marché</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="valider_proforma.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <p>Vous êtes sur le point de valider l'offre du fournisseur :</p>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center">Fournisseur<strong id="modal_fournisseur"></strong></li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">Montant<strong id="modal_montant"></strong></li>
                        </ul>
                        <div class="mb-3">
                            <label for="fichier_pv" class="form-label">Joindre le PV de sélection signé <span class="text-danger">*</span></label>
                            <input class="form-control" type="file" id="fichier_pv" name="fichier_pv" required>
                        </div>
                        <div class="form-text">Cette action est irréversible. Elle créera le marché correspondant et rejettera les autres offres.</div>
                        <input type="hidden" name="reponse_id" id="modal_reponse_id">
                        <input type="hidden" name="demande_id" value="<?= htmlspecialchars($demande_id) ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Confirmer et Créer le Marché</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modalValiderOffre = document.getElementById('modalValiderOffre');
        if (modalValiderOffre) {
            modalValiderOffre.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                const reponseId = button.getAttribute('data-reponse-id');
                const fournisseur = button.getAttribute('data-fournisseur');
                const montant = button.getAttribute('data-montant');

                document.getElementById('modal_reponse_id').value = reponseId;
                document.getElementById('modal_fournisseur').textContent = fournisseur;
                document.getElementById('modal_montant').textContent = montant;
            });
        }
    </script>
</body>
</html>