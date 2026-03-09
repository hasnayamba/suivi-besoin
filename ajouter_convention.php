<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// Fonction d'upload
function uploadFile($file, $prefix) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'png', 'xlsx'];
        if (in_array($ext, $allowed)) {
            $newName = $prefix . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            $dest = __DIR__ . '/uploads/' . $newName;
            if (!is_dir(__DIR__ . '/uploads/')) mkdir(__DIR__ . '/uploads/', 0755, true);
            if (move_uploaded_file($file['tmp_name'], $dest)) return $newName;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_convention'])) {
    try {
        // Upload des fichiers
        $fichier_conv = uploadFile($_FILES['fichier_convention'], 'CONV');
        $fichier_av = uploadFile($_FILES['fichier_avenant'], 'AV_CONV');

        if (empty($fichier_conv)) {
             throw new Exception("Le fichier de la convention est obligatoire.");
        }

        $sql = "INSERT INTO conventions (
            num_mandat, antenne, mode_selection, nom_partenaire, representant_legal, type_convention,
            num_convention, objet_convention, date_debut, date_fin, duree, intervention_appui,
            periodicite_paiement, montant_global, modalites_paiement, avenant_changement,
            paiements_effectues, solde_restant, statut, observations, fichier_convention, fichier_avenant
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['num_mandat'], $_POST['antenne'], $_POST['mode_selection'], $_POST['nom_partenaire'],
            $_POST['representant_legal'], $_POST['type_convention'], $_POST['num_convention'],
            $_POST['objet_convention'], 
            !empty($_POST['date_debut']) ? $_POST['date_debut'] : null,
            !empty($_POST['date_fin']) ? $_POST['date_fin'] : null,
            $_POST['duree'], $_POST['intervention_appui'], $_POST['periodicite_paiement'],
            !empty($_POST['montant_global']) ? $_POST['montant_global'] : 0,
            $_POST['modalites_paiement'], $_POST['avenant_changement'],
            !empty($_POST['paiements_effectues']) ? $_POST['paiements_effectues'] : 0,
            !empty($_POST['solde_restant']) ? $_POST['solde_restant'] : 0,
            $_POST['statut'], $_POST['observations'], $fichier_conv, $fichier_av
        ]);

        $_SESSION['success'] = "Convention ajoutée avec succès.";
        header('Location: convention_dashboard.php');
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nouvelle Convention</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'sidebar_convention.php'; ?>
    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
            <h2>Ajouter une Convention</h2>
        </header>
        <main class="p-4">
            <?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger"><?= $_SESSION['error'] ?></div><?php endif; ?>
            
            <div class="card">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        
                        <h5 class="text-primary">Informations Générales</h5>
                        <div class="row mb-3">
                            <div class="col-md-3"><label class="form-label">N° Mandat</label><input type="text" class="form-control" name="num_mandat"></div>
                            <div class="col-md-3"><label class="form-label">Antenne</label><input type="text" class="form-control" name="antenne"></div>
                            <div class="col-md-3"><label class="form-label">Type de convention</label><input type="text" class="form-control" name="type_convention"></div>
                            <div class="col-md-3"><label class="form-label">N° de la convention</label><input type="text" class="form-control" name="num_convention"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Objet de la convention <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="objet_convention" required></textarea>
                        </div>

                        <h5 class="text-primary mt-4">Partenaire</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Nom du partenaire <span class="text-danger">*</span></label><input type="text" class="form-control" name="nom_partenaire" required></div>
                            <div class="col-md-4"><label class="form-label">Représentant légal</label><input type="text" class="form-control" name="representant_legal"></div>
                            <div class="col-md-4"><label class="form-label">Mode de sélection</label><input type="text" class="form-control" name="mode_selection"></div>
                        </div>

                        <h5 class="text-primary mt-4">Dates & Durée</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Début / Signature</label><input type="date" class="form-control" name="date_debut" id="date_debut"></div>
                            <div class="col-md-4"><label class="form-label">Fin de la convention</label><input type="date" class="form-control" name="date_fin" id="date_fin"></div>
                            <div class="col-md-4"><label class="form-label">Durée (Calculée)</label><input type="text" class="form-control bg-light" name="duree" id="duree" readonly></div>
                        </div>

                        <h5 class="text-primary mt-4">Finances</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Montant Convention/Annuel</label><input type="number" class="form-control" name="montant_global" id="montant_global" step="0.01"></div>
                            <div class="col-md-4"><label class="form-label">Paiements effectués</label><input type="number" class="form-control" name="paiements_effectues" id="paiements_effectues" step="0.01"></div>
                            <div class="col-md-4"><label class="form-label fw-bold text-danger">Solde Restant</label><input type="number" class="form-control bg-light fw-bold" name="solde_restant" id="solde_restant" readonly></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Périodicité de paiement</label><input type="text" class="form-control" name="periodicite_paiement"></div>
                            <div class="col-md-6"><label class="form-label">Intervention, appui</label><input type="text" class="form-control" name="intervention_appui"></div>
                        </div>

                        <h5 class="text-primary mt-4">Détails & Fichiers</h5>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Fichier Convention <span class="text-danger">*</span></label><input type="file" class="form-control" name="fichier_convention" required></div>
                            <div class="col-md-6"><label class="form-label">Fichier Avenant</label><input type="file" class="form-control" name="fichier_avenant"></div>
                        </div>
                        <div class="mb-3"><label class="form-label">Avenant et changement</label><textarea class="form-control" name="avenant_changement" rows="2"></textarea></div>
                        <div class="mb-3"><label class="form-label">Modalités de Paiement</label><textarea class="form-control" name="modalites_paiement" rows="2"></textarea></div>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Statut</label>
                                <select class="form-select" name="statut">
                                    <option value="En cours">En cours</option>
                                    <option value="Clôturé">Clôturé</option>
                                </select>
                            </div>
                            <div class="col-md-6"><label class="form-label">Observations</label><input type="text" class="form-control" name="observations"></div>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="submit_convention" class="btn btn-success btn-lg">Enregistrer la Convention</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // Calcul automatique de la durée et du solde
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