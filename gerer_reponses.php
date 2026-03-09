<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ET RÉCUPÉRATION DES DONNÉES ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// Récupérer l'ID de la demande de proforma
$demande_id = $_GET['id'] ?? null;
if (!$demande_id) {
    $_SESSION['error'] = "Aucun identifiant de demande spécifié.";
    header('Location: demande_proforma.php');
    exit();
}

// =========================================================================
// GESTION DE L'AJOUT D'UNE PROFORMA REÇUE
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proforma_recue'])) {
    
    // On revient au champ libre textuel !
    $fournisseur_nom = trim($_POST['fournisseur']); 
    $montant = trim($_POST['montant']);
    $delai = trim($_POST['delai']);
    $date_reception = trim($_POST['date_reception']);
    $fichier_nom = null;

    if (empty($fournisseur_nom) || empty($montant) || empty($date_reception)) {
        $_SESSION['error'] = "Veuillez remplir tous les champs obligatoires.";
    } else {
        // Upload du fichier PDF scanné
        if (isset($_FILES['fichier_proforma']) && $_FILES['fichier_proforma']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['fichier_proforma']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'jpg', 'png', 'jpeg'])) {
                $uploadFileDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
                
                // On nettoie le nom du fournisseur pour le fichier
                $clean_fournisseur = preg_replace('/[^A-Za-z0-9\-]/', '_', $fournisseur_nom);
                $newFileName = 'PROFORMA_' . $demande_id . '_' . $clean_fournisseur . '_' . time() . '.' . $ext;
                
                if (move_uploaded_file($_FILES['fichier_proforma']['tmp_name'], $uploadFileDir . $newFileName)) {
                    $fichier_nom = $newFileName;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            // 1. Insérer la réponse dans la table des proformas reçues
            $sql = "INSERT INTO proformas_recus (demande_proforma_id, fournisseur, montant, delai, date_reception, fichier, statut) 
                    VALUES (:demande_id, :fournisseur, :montant, :delai, :date_reception, :fichier, 'En attente')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':demande_id' => $demande_id,
                ':fournisseur' => $fournisseur_nom, 
                ':montant' => $montant,
                ':delai' => $delai,
                ':date_reception' => $date_reception,
                ':fichier' => $fichier_nom
            ]);

            // 2. Mettre à jour le suivi (Si le nom tapé correspond à un fournisseur qu'on avait contacté)
            $stmt_update_liaison = $pdo->prepare("
                UPDATE proforma_fournisseurs pf 
                JOIN fournisseurs f ON pf.fournisseur_id = f.id 
                SET pf.statut_reponse = 'Reçu' 
                WHERE pf.proforma_id = ? AND f.nom = ?
            ");
            $stmt_update_liaison->execute([$demande_id, $fournisseur_nom]);

            // 3. Mettre à jour le statut global de la demande proforma
            $stmt_update_demande = $pdo->prepare("UPDATE demandes_proforma SET statut = 'Réponses en cours' WHERE id = ? AND statut = 'En attente'");
            $stmt_update_demande->execute([$demande_id]);

            $pdo->commit();
            $_SESSION['success'] = "L'offre de '$fournisseur_nom' a été enregistrée avec succès.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur SQL: " . $e->getMessage();
        }
    }
    header('Location: gerer_reponses.php?id=' . $demande_id);
    exit();
}

// =========================================================================
// RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE
// =========================================================================

// 1. Infos de la demande
$stmt_demande = $pdo->prepare("SELECT * FROM demandes_proforma WHERE id = :id");
$stmt_demande->execute([':id' => $demande_id]);
$demande = $stmt_demande->fetch();

if (!$demande) {
    $_SESSION['error'] = "La demande de proforma est introuvable.";
    header('Location: demande_proforma.php');
    exit();
}

// 2. Les réponses déjà saisies
$stmt_reponses = $pdo->prepare("SELECT * FROM proformas_recus WHERE demande_proforma_id = :id ORDER BY montant ASC");
$stmt_reponses->execute([':id' => $demande_id]);
$reponses = $stmt_reponses->fetchAll();

// 3. LA LISTE DES FOURNISSEURS CONTACTÉS (Pour le bloc Suivi)
$stmt_contactes = $pdo->prepare("
    SELECT f.nom, pf.statut_reponse 
    FROM fournisseurs f
    JOIN proforma_fournisseurs pf ON f.id = pf.fournisseur_id
    WHERE pf.proforma_id = ?
");
$stmt_contactes->execute([$demande_id]);
$fournisseurs_contactes = $stmt_contactes->fetchAll();

// 4. TOUS LES FOURNISSEURS DE LA BDD (Pour l'auto-complétion de la saisie libre)
$tous_les_fournisseurs = $pdo->query("SELECT nom FROM fournisseurs ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN);

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
        <?php include 'header.php'; ?>
        
        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Gestion des Réponses Proforma</h2>
                        <p class="text-muted mb-0 small">Demande: <?= htmlspecialchars($demande['titre_besoin']) ?> (<code><?= htmlspecialchars($demande['id']) ?></code>)</p>
                    </div>
                    <a href="demande_proforma.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour au tableau</a>
                </div>
            </header>

            <main class="flex-fill overflow-auto p-4">
                 <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3 mb-4">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-header bg-white fw-bold text-muted small text-uppercase border-bottom-0">
                                <i class="bi bi-list-check me-2"></i>Rappel des envois d'emails
                            </div>
                            <ul class="list-group list-group-flush small">
                                <?php if(empty($fournisseurs_contactes)): ?>
                                    <li class="list-group-item text-muted text-center py-3">Aucun fournisseur n'a été contacté via le système pour ce besoin.</li>
                                <?php else: foreach ($fournisseurs_contactes as $contact): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-light mb-1 border rounded">
                                        <?= htmlspecialchars($contact['nom']) ?>
                                        <?php if ($contact['statut_reponse'] === 'Reçu'): ?>
                                            <span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> Reçu</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill">En attente</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="col-md-9 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="text-primary mb-0"><i class="bi bi-bar-chart-fill me-2"></i>Offres classées par montant</h5>
                            <button class="btn btn-primary shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#addProformaRecueModal" <?= ($demande['statut'] == 'Validé') ? 'disabled' : '' ?>>
                                <i class="bi bi-plus-circle me-2"></i>Saisir une offre reçue
                            </button>
                        </div>

                        <div class="card shadow-sm border-0">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Fournisseur</th>
                                                <th>Montant Total</th>
                                                <th>Délai Livraison</th>
                                                <th>Statut</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($reponses)): ?>
                                                <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-3"></i>Aucune offre saisie pour le moment.</td></tr>
                                            <?php else: foreach ($reponses as $index => $reponse): 
                                                $row_class = '';
                                                if ($reponse['statut'] == 'Validé') $row_class = 'table-success border-success border-2';
                                                elseif ($reponse['statut'] == 'Rejeté') $row_class = 'text-muted opacity-50';
                                                
                                                // Le premier de la boucle (index 0) est le moins disant (car trié par montant ASC)
                                                $is_moin_disant = ($index === 0 && $demande['statut'] !== 'Validé' && $reponse['statut'] !== 'Rejeté');
                                            ?>
                                                <tr class="<?= $row_class ?>">
                                                    <td>
                                                        <strong><?= htmlspecialchars($reponse['fournisseur']) ?></strong>
                                                        <?php if ($is_moin_disant): ?>
                                                            <br><span class="badge bg-success small">Moins disant</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-primary fs-6"><strong><?= number_format($reponse['montant'], 0, ',', ' ') . ' CFA' ?></strong></td>
                                                    <td><?= htmlspecialchars($reponse['delai']) ?></td>
                                                    <td>
                                                        <?php if($reponse['statut'] == 'Validé'): ?>
                                                            <span class="badge bg-success">Retenu</span>
                                                        <?php elseif($reponse['statut'] == 'Rejeté'): ?>
                                                            <span class="badge bg-danger">Rejeté</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">En attente de choix</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if (!empty($reponse['fichier'])): ?>
                                                            <a href="uploads/<?= htmlspecialchars($reponse['fichier']) ?>" class="btn btn-sm btn-outline-secondary" title="Voir la proforma" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a>
                                                        <?php endif; ?>

                                                        <?php if ($demande['statut'] !== 'Validé' && $reponse['statut'] == 'En attente'): ?>
                                                            <button type="button" class="btn btn-sm btn-success ms-1 valider-offre-btn shadow-sm" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#modalValiderOffre"
                                                                    data-reponse-id="<?= $reponse['id'] ?>"
                                                                    data-fournisseur="<?= htmlspecialchars($reponse['fournisseur']) ?>"
                                                                    data-montant="<?= number_format($reponse['montant'], 0, ',', ' ') . ' CFA' ?>">
                                                                <i class="bi bi-trophy-fill me-1"></i> Retenir cette offre
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
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <div class="modal fade" id="addProformaRecueModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-primary">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Saisir une offre reçue</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="gerer_reponses.php?id=<?= htmlspecialchars($demande_id) ?>" method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        
                        <div class="mb-3">
                            <label for="fournisseur" class="form-label fw-bold">Nom du Fournisseur <span class="text-danger">*</span></label>
                            
                            <input type="text" class="form-control border-primary" id="fournisseur" name="fournisseur" list="fournisseurs_list" required placeholder="Tapez le nom ou sélectionnez-le...">
                            <datalist id="fournisseurs_list">
                                <?php foreach ($tous_les_fournisseurs as $f_nom): ?>
                                    <option value="<?= htmlspecialchars($f_nom) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted d-block mt-1">Saisissez le nom exact du fournisseur. S'il existe déjà dans la base, il vous sera suggéré.</small>
                        </div>

                        <div class="mb-3">
                            <label for="montant" class="form-label fw-bold">Montant Total Proposé (CFA) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" id="montant" name="montant" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="delai" class="form-label fw-bold">Délai de livraison</label>
                                <input type="text" class="form-control" id="delai" name="delai" placeholder="Ex: 15 jours">
                            </div>
                            <div class="col-md-6">
                                <label for="date_reception" class="form-label fw-bold">Date de réception <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_reception" name="date_reception" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="mb-3 bg-light p-3 rounded">
                            <label for="fichier_proforma" class="form-label fw-bold"><i class="bi bi-file-earmark-pdf me-1"></i>Fichier de la proforma (Scan)</label>
                            <input class="form-control" type="file" id="fichier_proforma" name="fichier_proforma" accept=".pdf,.jpg,.png,.jpeg">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="submit_proforma_recue" class="btn btn-primary px-4 fw-bold">Enregistrer l'offre</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalValiderOffre" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-success">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-trophy-fill me-2"></i>Retenir cette offre</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="valider_proforma.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <p>Vous êtes sur le point de valider l'offre du fournisseur :</p>
                        <ul class="list-group mb-3 shadow-sm">
                            <li class="list-group-item d-flex justify-content-between align-items-center bg-light">
                                Fournisseur <strong id="modal_fournisseur" class="text-dark"></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center bg-light">
                                Montant <strong id="modal_montant" class="text-primary fs-5"></strong>
                            </li>
                        </ul>
                        
                        <div class="mb-3 mt-4">
                            <label for="fichier_pv" class="form-label fw-bold">Joindre le PV de dépouillement signé <span class="text-danger">*</span></label>
                            <input class="form-control border-success" type="file" id="fichier_pv" name="fichier_pv" accept=".pdf,.jpg,.png" required>
                        </div>
                        
                        <div class="alert alert-warning small mb-0">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Cette action va figer la demande, rejeter les autres offres et préparer le marché.
                        </div>
                        
                        <input type="hidden" name="reponse_id" id="modal_reponse_id">
                        <input type="hidden" name="demande_id" value="<?= htmlspecialchars($demande_id) ?>">
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success fw-bold px-4">Confirmer la sélection</button>
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
                document.getElementById('modal_reponse_id').value = button.getAttribute('data-reponse-id');
                document.getElementById('modal_fournisseur').textContent = button.getAttribute('data-fournisseur');
                document.getElementById('modal_montant').textContent = button.getAttribute('data-montant');
            });
        }
    </script>
</body>
</html>