<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- RÉCUPÉRATION DES PROJETS POUR LES MANDATS ---
try {
    $stmt_projets = $pdo->query("SELECT id, nom FROM projets ORDER BY nom ASC");
    $projets = $stmt_projets->fetchAll();
} catch (PDOException $e) {
    $projets = [];
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

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_convention'])) {
    try {
        // Upload des fichiers
        $fichier_conv = uploadFile($_FILES['fichier_convention'], 'CONV');
        $fichier_av = uploadFile($_FILES['fichier_avenant'], 'AV_CONV');

        if (empty($fichier_conv)) {
             throw new Exception("Le fichier de la convention est obligatoire.");
        }

        // Gestion dynamique du Type de Convention (Si "Autre" est choisi)
        $type_final = $_POST['type_convention'];
        if ($type_final === 'Autre' && !empty($_POST['nouveau_type'])) {
            $type_final = trim($_POST['nouveau_type']);
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
            $_POST['num_mandat'], 
            $_POST['antenne'], 
            $_POST['mode_selection'], 
            $_POST['nom_partenaire'],
            $_POST['representant_legal'], 
            $type_final, // Type traité
            $_POST['num_convention'],
            $_POST['objet_convention'], 
            !empty($_POST['date_debut']) ? $_POST['date_debut'] : null,
            !empty($_POST['date_fin']) ? $_POST['date_fin'] : null,
            $_POST['duree'], 
            $_POST['intervention_appui'], 
            $_POST['periodicite_paiement'],
            !empty($_POST['montant_global']) ? $_POST['montant_global'] : 0,
            $_POST['modalites_paiement'], 
            $_POST['avenant_changement'],
            !empty($_POST['paiements_effectues']) ? $_POST['paiements_effectues'] : 0,
            !empty($_POST['solde_restant']) ? $_POST['solde_restant'] : 0,
            $_POST['statut'], 
            $_POST['observations'], 
            $fichier_conv, 
            $fichier_av
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Convention | Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .main-content { background-color: #f8f9fa; min-height: 100vh; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div class="d-flex vh-100">
    <?php include 'sidebar_convention.php'; ?>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    
                    <div>
                        <h2 class="mb-0 h4 fw-bold text-primary">Nouvelle Convention</h2>
                        <p class="text-muted mb-0 small">Enregistrement des accords et partenariats</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        
                        <h5 class="text-primary fw-bold mb-3"><i class="bi bi-info-square me-2"></i>Informations Générales</h5>
                        <hr class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Mandat / Projet</label>
                                <select class="form-select" name="num_mandat">
                                    <option value="" selected disabled>Choisir un projet...</option>
                                    <?php foreach ($projets as $p): ?>
                                        <option value="<?= htmlspecialchars($p['nom']) ?>"><?= htmlspecialchars($p['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Antenne (Région)</label>
                                <select class="form-select" name="antenne">
                                    <option value="" selected disabled>Choisir une région...</option>
                                    <option value="Agadez">Agadez</option>
                                    <option value="Diffa">Diffa</option>
                                    <option value="Dosso">Dosso</option>
                                    <option value="Maradi">Maradi</option>
                                    <option value="Niamey">Niamey</option>
                                    <option value="Tahoua">Tahoua</option>
                                    <option value="Tillabéri">Tillabéri</option>
                                    <option value="Zinder">Zinder</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Type de convention</label>
                                <select class="form-select" name="type_convention" id="select_type_conv" onchange="toggleNouveauType()">
                                    <option value="" selected disabled>Choisir un type...</option>
                                    <option value="Convention de partenariat">Convention de partenariat</option>
                                    <option value="Convention de financement">Convention de financement</option>
                                    <option value="Protocole d'accord (MoU)">Protocole d'accord (MoU)</option>
                                    <option value="Convention de stage">Convention de stage</option>
                                    <option value="Autre">-- Autre (Saisir) --</option>
                                </select>
                                <div id="div_nouveau_type" class="mt-2" style="display: none;">
                                    <input type="text" class="form-control border-primary" name="nouveau_type" placeholder="Précisez le type...">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">N° de la convention</label>
                                <input type="text" class="form-control" name="num_convention" placeholder="Ex: CV-2026-05">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Objet de la convention <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="objet_convention" rows="2" required placeholder="Description courte de l'objet..."></textarea>
                        </div>

                        <h5 class="text-primary fw-bold mt-4 mb-3"><i class="bi bi-people me-2"></i>Partenaire & Sélection</h5>
                        <hr class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Nom du partenaire <span class="text-danger">*</span></label><input type="text" class="form-control" name="nom_partenaire" required></div>
                            <div class="col-md-4"><label class="form-label">Représentant légal</label><input type="text" class="form-control" name="representant_legal"></div>
                            <div class="col-md-4"><label class="form-label">Mode de sélection</label><input type="text" class="form-control" name="mode_selection" placeholder="Ex: Entente directe, Appel à projet..."></div>
                        </div>

                        <h5 class="text-primary fw-bold mt-4 mb-3"><i class="bi bi-calendar-range me-2"></i>Dates & Durée</h5>
                        <hr class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Date de Signature</label><input type="date" class="form-control" name="date_debut" id="date_debut"></div>
                            <div class="col-md-4"><label class="form-label">Date de Fin</label><input type="date" class="form-control" name="date_fin" id="date_fin"></div>
                            <div class="col-md-4"><label class="form-label">Durée</label><input type="text" class="form-control bg-light fw-bold" name="duree" id="duree" readonly></div>
                        </div>

                        <h5 class="text-primary fw-bold mt-4 mb-3"><i class="bi bi-cash-stack me-2"></i>Finances (CFA)</h5>
                        <hr class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-4"><label class="form-label">Montant Global / Annuel</label><input type="number" class="form-control" name="montant_global" id="montant_global"></div>
                            <div class="col-md-4"><label class="form-label text-success fw-bold">Paiements effectués</label><input type="number" class="form-control border-success" name="paiements_effectues" id="paiements_effectues" value="0"></div>
                            <div class="col-md-4"><label class="form-label text-danger fw-bold">Solde Restant</label><input type="number" class="form-control bg-light fw-bold text-danger" name="solde_restant" id="solde_restant" readonly></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6"><label class="form-label">Périodicité de paiement</label><input type="text" class="form-control" name="periodicite_paiement" placeholder="Ex: Trimestriel, à la signature..."></div>
                            <div class="col-md-6"><label class="form-label">Intervention / Appui financier</label><input type="text" class="form-control" name="intervention_appui"></div>
                        </div>

                        <h5 class="text-primary fw-bold mt-4 mb-3"><i class="bi bi-file-earmark-pdf me-2"></i>Documents & Statut</h5>
                        <hr class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Scan Convention (PDF) <span class="text-danger">*</span></label>
                                <input type="file" class="form-control shadow-sm" name="fichier_convention" accept=".pdf,.doc,.docx" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Scan Avenant (Optionnel)</label>
                                <input type="file" class="form-control" name="fichier_avenant" accept=".pdf,.doc,.docx">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Statut Actuel</label>
                                <select class="form-select fw-bold" name="statut">
                                    <option value="En cours" class="text-primary">En cours</option>
                                    <option value="Clôturé" class="text-muted">Clôturé</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Observations</label>
                                <input type="text" class="form-control" name="observations">
                            </div>
                        </div>

                        <div class="text-end mt-5">
                            <a href="convention_dashboard.php" class="btn btn-light border px-4 me-2">Annuler</a>
                            <button type="submit" name="submit_convention" class="btn btn-success px-5 fw-bold shadow">
                                <i class="bi bi-check-circle me-2"></i>Enregistrer la Convention
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // Gestion du type "Autre"
    function toggleNouveauType() {
        const select = document.getElementById('select_type_conv');
        const divNouveau = document.getElementById('div_nouveau_type');
        if (select.value === 'Autre') {
            divNouveau.style.display = 'block';
            divNouveau.querySelector('input').setAttribute('required', 'required');
        } else {
            divNouveau.style.display = 'none';
            divNouveau.querySelector('input').removeAttribute('required');
        }
    }

    // Calcul automatique Durée et Solde
    const d1 = document.getElementById('date_debut');
    const d2 = document.getElementById('date_fin');
    const dur = document.getElementById('duree');
    const mt = document.getElementById('montant_global');
    const paie = document.getElementById('paiements_effectues');
    const solde = document.getElementById('solde_restant');

    function calc() {
        // Calcul Durée (Années / Mois)
        if(d1.value && d2.value) {
            let start = new Date(d1.value);
            let end = new Date(d2.value);
            if(end > start) {
                let months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth());
                let years = Math.floor(months / 12);
                let remMonths = months % 12;
                let text = "";
                if (years > 0) text += years + (years > 1 ? " ans " : " an ");
                if (remMonths > 0) text += remMonths + " mois";
                dur.value = text.trim() || "Moins d'un mois";
            } else { dur.value = ""; }
        }
        // Calcul Solde
        let m = parseFloat(mt.value) || 0;
        let p = parseFloat(paie.value) || 0;
        solde.value = (m - p).toFixed(0);
    }

    [d1, d2, mt, paie].forEach(el => el.addEventListener('input', calc));
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>