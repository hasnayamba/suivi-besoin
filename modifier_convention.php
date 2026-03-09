<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id) { header('Location: convention_dashboard.php'); exit(); }

// Fonction d'upload
function uploadFile($file, $prefix) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $newName = $prefix . '_' . time() . '_' . rand(100,999) . '.' . $ext;
        $dest = __DIR__ . '/uploads/' . $newName;
        if (move_uploaded_file($file['tmp_name'], $dest)) return $newName;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_update'])) {
    try {
        // Gestion fichiers
        $fichier_conv = $_POST['old_fichier_convention'];
        if (!empty($_FILES['fichier_convention']['name'])) {
            $newF = uploadFile($_FILES['fichier_convention'], 'CONV');
            if ($newF) {
                 if ($fichier_conv && file_exists(__DIR__ . '/uploads/' . $fichier_conv)) unlink(__DIR__ . '/uploads/' . $fichier_conv);
                 $fichier_conv = $newF;
            }
        }

        $fichier_av = $_POST['old_fichier_avenant'];
        if (!empty($_FILES['fichier_avenant']['name'])) {
            $newF = uploadFile($_FILES['fichier_avenant'], 'AV_CONV');
            if ($newF) {
                 if ($fichier_av && file_exists(__DIR__ . '/uploads/' . $fichier_av)) unlink(__DIR__ . '/uploads/' . $fichier_av);
                 $fichier_av = $newF;
            }
        }

        $sql = "UPDATE conventions SET 
            num_mandat=?, antenne=?, mode_selection=?, nom_partenaire=?, representant_legal=?, type_convention=?,
            num_convention=?, objet_convention=?, date_debut=?, date_fin=?, duree=?, intervention_appui=?,
            periodicite_paiement=?, montant_global=?, modalites_paiement=?, avenant_changement=?,
            paiements_effectues=?, solde_restant=?, statut=?, observations=?, 
            fichier_convention=?, fichier_avenant=?
            WHERE id=?";
        
        $stmt = $pdo->prepare($sql);
        
        $emptyToNull = function($value) { return $value === '' ? null : $value; };

        $stmt->execute([
            $emptyToNull($_POST['num_mandat']), $emptyToNull($_POST['antenne']), $emptyToNull($_POST['mode_selection']), 
            $_POST['nom_partenaire'], $emptyToNull($_POST['representant_legal']), $emptyToNull($_POST['type_convention']), 
            $emptyToNull($_POST['num_convention']), $_POST['objet_convention'], 
            $emptyToNull($_POST['date_debut']), $emptyToNull($_POST['date_fin']), 
            $emptyToNull($_POST['duree']), $emptyToNull($_POST['intervention_appui']), $emptyToNull($_POST['periodicite_paiement']),
            $emptyToNull($_POST['montant_global']), $emptyToNull($_POST['modalites_paiement']), $emptyToNull($_POST['avenant_changement']),
            $emptyToNull($_POST['paiements_effectues']), $emptyToNull($_POST['solde_restant']),
            $_POST['statut'], $emptyToNull($_POST['observations']), 
            $fichier_conv, $fichier_av,
            $id
        ]);

        $_SESSION['success'] = "Convention mise à jour.";
        header('Location: convention_dashboard.php');
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur : " . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT * FROM conventions WHERE id = ?");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) die("Introuvable");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Modifier Convention</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'sidebar_convention.php'; ?>
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3"><h2>Modifier la Convention</h2></header>
        <main class="p-4">
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger"><?= $_SESSION['error'] ?></div><?php endif; ?>
            
            <div class="card">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <input type="hidden" name="old_fichier_convention" value="<?= htmlspecialchars($c['fichier_convention'] ?? '') ?>">
                        <input type="hidden" name="old_fichier_avenant" value="<?= htmlspecialchars($c['fichier_avenant'] ?? '') ?>">

                        <h5 class="text-primary">Informations Générales</h5>
                        <div class="row mb-3">
                            <div class="col-md-3"><label class="form-label">N° Mandat</label><input type="text" class="form-control" name="num_mandat" value="<?= htmlspecialchars($c['num_mandat'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label">Antenne</label><input type="text" class="form-control" name="antenne" value="<?= htmlspecialchars($c['antenne'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label">Type de convention</label><input type="text" class="form-control" name="type_convention" value="<?= htmlspecialchars($c['type_convention'] ?? '') ?>"></div>
                            <div class="col-md-3"><label class="form-label">N° de la convention</label><input type="text" class="form-control" name="num_convention" value="<?= htmlspecialchars($c['num_convention'] ?? '') ?>"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Objet de la convention <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="objet_convention" required><?= htmlspecialchars($c['objet_convention'] ?? '') ?></textarea>
                        </div>

                        <h5 class="text-primary mt-4">Partenaire</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Nom du partenaire <span class="text-danger">*</span></label><input type="text" class="form-control" name="nom_partenaire" value="<?= htmlspecialchars($c['nom_partenaire'] ?? '') ?>" required></div>
                            <div class="col-md-4"><label class="form-label">Représentant légal</label><input type="text" class="form-control" name="representant_legal" value="<?= htmlspecialchars($c['representant_legal'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label">Mode de sélection</label><input type="text" class="form-control" name="mode_selection" value="<?= htmlspecialchars($c['mode_selection'] ?? '') ?>"></div>
                        </div>

                        <h5 class="text-primary mt-4">Dates & Durée</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Début / Signature</label><input type="date" class="form-control" name="date_debut" id="date_debut" value="<?= htmlspecialchars($c['date_debut'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label">Fin de la convention</label><input type="date" class="form-control" name="date_fin" id="date_fin" value="<?= htmlspecialchars($c['date_fin'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label">Durée</label><input type="text" class="form-control bg-light" name="duree" id="duree" value="<?= htmlspecialchars($c['duree'] ?? '') ?>" readonly></div>
                        </div>

                        <h5 class="text-primary mt-4">Finances</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Montant Global</label><input type="number" class="form-control" name="montant_global" id="montant_global" step="0.01" value="<?= htmlspecialchars($c['montant_global'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label">Paiements effectués</label><input type="number" class="form-control" name="paiements_effectues" id="paiements_effectues" step="0.01" value="<?= htmlspecialchars($c['paiements_effectues'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="form-label fw-bold text-danger">Solde Restant</label><input type="number" class="form-control bg-light fw-bold" name="solde_restant" id="solde_restant" value="<?= htmlspecialchars($c['solde_restant'] ?? '') ?>" readonly></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Périodicité de paiement</label><input type="text" class="form-control" name="periodicite_paiement" value="<?= htmlspecialchars($c['periodicite_paiement'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Intervention, appui</label><input type="text" class="form-control" name="intervention_appui" value="<?= htmlspecialchars($c['intervention_appui'] ?? '') ?>"></div>
                        </div>

                        <h5 class="text-primary mt-4">Détails & Fichiers</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Fichier Convention</label>
                                <?php if(!empty($c['fichier_convention'])): ?>
                                    <div class="mb-1 small"><a href="uploads/<?= rawurlencode($c['fichier_convention']) ?>" target="_blank">Voir actuel</a></div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="fichier_convention">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fichier Avenant</label>
                                <?php if(!empty($c['fichier_avenant'])): ?>
                                    <div class="mb-1 small"><a href="uploads/<?= rawurlencode($c['fichier_avenant']) ?>" target="_blank">Voir actuel</a></div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="fichier_avenant">
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label">Avenant et changement</label><textarea class="form-control" name="avenant_changement" rows="2"><?= htmlspecialchars($c['avenant_changement'] ?? '') ?></textarea></div>
                        <div class="mb-3"><label class="form-label">Modalités de Paiement</label><textarea class="form-control" name="modalites_paiement" rows="2"><?= htmlspecialchars($c['modalites_paiement'] ?? '') ?></textarea></div>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Statut</label>
                                <select class="form-select" name="statut">
                                    <option value="En cours" <?= ($c['statut'] == 'En cours') ? 'selected' : '' ?>>En cours</option>
                                    <option value="Clôturé" <?= ($c['statut'] == 'Clôturé') ? 'selected' : '' ?>>Clôturé</option>
                                </select>
                            </div>
                            <div class="col-md-6"><label class="form-label">Observations</label><input type="text" class="form-control" name="observations" value="<?= htmlspecialchars($c['observations'] ?? '') ?>"></div>
                        </div>

                        <div class="text-end">
                            <a href="convention_dashboard.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" name="submit_update" class="btn btn-primary">Mettre à jour</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    const d1 = document.getElementById('date_debut');
    const d2 = document.getElementById('date_fin');
    const dur = document.getElementById('duree');
    const mt = document.getElementById('montant_global');
    const paie = document.getElementById('paiements_effectues');
    const solde = document.getElementById('solde_restant');

    function calc() {
        // Durée
        if(d1.value && d2.value) {
            let diff = new Date(d2.value) - new Date(d1.value);
            let days = Math.ceil(diff / (1000 * 60 * 60 * 24));
            dur.value = days > 0 ? days + " jours" : "";
        }
        // Solde
        let m = parseFloat(mt.value) || 0;
        let p = parseFloat(paie.value) || 0;
        solde.value = (m - p).toFixed(2);
    }

    [d1, d2, mt, paie].forEach(el => el.addEventListener('input', calc));
</script>
</body>
</html>