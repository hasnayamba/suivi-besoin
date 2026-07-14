<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- RÉCUPÉRATION DES PROJETS POUR LA LISTE DES MANDATS ---
try {
    $stmt_projets = $pdo->query("SELECT id, nom FROM projets ORDER BY nom ASC");
    $projets = $stmt_projets->fetchAll();
} catch (PDOException $e) {
    $projets = [];
}

// Fonction d'upload sécurisée
function uploadFile($file, $prefix) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'png'];
        if (in_array($ext, $allowed)) {
            $newName = $prefix . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            $dest = __DIR__ . '/uploads/' . $newName;
            if (!is_dir(__DIR__ . '/uploads/')) {
                mkdir(__DIR__ . '/uploads/', 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                return $newName;
            }
        }
    }
    return null;
}

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contrat'])) {
    
    if (empty($_FILES['fichier_contrat']['name'])) {
        $_SESSION['error'] = "Le fichier du contrat est obligatoire.";
    } else {
        try {
            $fichier_contrat = uploadFile($_FILES['fichier_contrat'], 'CONTRAT');
            $fichier_avenant = uploadFile($_FILES['fichier_avenant'], 'AVENANT');

            if (!$fichier_contrat) {
                throw new Exception("Erreur lors du téléchargement du contrat.");
            }

            // Gestion dynamique du Type de Contrat (Si "Autre" est choisi)
            $type_final = $_POST['type_contrat'];
            if ($type_final === 'Autre' && !empty($_POST['nouveau_type'])) {
                $type_final = trim($_POST['nouveau_type']);
            }

            // Récupération de l'ID du projet depuis le nom (optionnel)
            $num_mandat = null;
            if (!empty($_POST['num_mandat'])) {
                $stmt = $pdo->prepare("SELECT id FROM projets WHERE nom = ?");
                $stmt->execute([$_POST['num_mandat']]);
                $num_mandat = $stmt->fetchColumn();
            }

            $sql = "INSERT INTO contrats (
                        num_mandat, antenne, mode_selection, nom_fournisseur, representant_legal, type_contrat, 
                        num_contrat, objet_contrat, montant_max_annuel, montant_ht, paiement_effectue, 
                        date_debut, date_fin_prevue, duree_previsionnelle, reconduction_tacite, avenant_changement, 
                        date_avenant, date_fin_avenant, statut, date_fin_effective, modalites_paiement, observations,
                        fichier_contrat, fichier_avenant
                    ) VALUES (
                        :num_mandat, :antenne, :mode_selection, :nom_fournisseur, :representant_legal, :type_contrat, 
                        :num_contrat, :objet_contrat, :montant_max_annuel, :montant_ht, :paiement_effectue,
                        :date_debut, :date_fin_prevue, :duree_previsionnelle, :reconduction_tacite, :avenant_changement, 
                        :date_avenant, :date_fin_avenant, :statut, :date_fin_effective, :modalites_paiement, :observations,
                        :fichier_contrat, :fichier_avenant
                    )";
            
            $stmt = $pdo->prepare($sql);
            
            $emptyToNull = function($value) { return $value === '' ? null : $value; };

            $stmt->execute([
                ':num_mandat' => $num_mandat,
                ':antenne' => $emptyToNull($_POST['antenne']),
                ':mode_selection' => $emptyToNull($_POST['mode_selection']),
                ':nom_fournisseur' => $_POST['nom_fournisseur'],
                ':representant_legal' => $emptyToNull($_POST['representant_legal']),
                ':type_contrat' => $type_final,
                ':num_contrat' => $emptyToNull($_POST['num_contrat']),
                ':objet_contrat' => $_POST['objet_contrat'],
                ':montant_max_annuel' => $emptyToNull($_POST['montant_max_annuel']),
                ':montant_ht' => $emptyToNull($_POST['montant_ht']),
                ':paiement_effectue' => $emptyToNull($_POST['paiement_effectue']),
                ':date_debut' => $emptyToNull($_POST['date_debut']),
                ':date_fin_prevue' => $emptyToNull($_POST['date_fin_prevue']),
                ':duree_previsionnelle' => $emptyToNull($_POST['duree_previsionnelle']),
                ':reconduction_tacite' => $emptyToNull($_POST['reconduction_tacite']),
                ':avenant_changement' => $emptyToNull($_POST['avenant_changement']),
                ':date_avenant' => $emptyToNull($_POST['date_avenant']),
                ':date_fin_avenant' => $emptyToNull($_POST['date_fin_avenant']),
                ':statut' => $_POST['statut'],
                ':date_fin_effective' => $emptyToNull($_POST['date_fin_effective']),
                ':modalites_paiement' => $emptyToNull($_POST['modalites_paiement']),
                ':observations' => $emptyToNull($_POST['observations']),
                ':fichier_contrat' => $fichier_contrat,
                ':fichier_avenant' => $fichier_avenant
            ]);
            
            // Log de l'activité
            if(function_exists('enregistrer_log')) {
                enregistrer_log($pdo, $_SESSION['user_id'], 'Contrats', 'Création', "Contrat créé pour " . $_POST['nom_fournisseur']);
            }

            $_SESSION['success'] = "Le contrat a été enregistré avec succès.";
            header('Location: contrat_dashboard.php');
            exit();
            
        } catch (PDOException $e) {
            // Afficher l'erreur détaillée en mode debug
            $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
            // Redirection pour afficher l'erreur
            header('Location: ajouter_contrat.php');
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = "Erreur : " . $e->getMessage();
            header('Location: ajouter_contrat.php');
            exit();
        }
    }
    header('Location: ajouter_contrat.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un Nouveau Contrat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php include 'sidebar_contrat.php'; ?>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div>
                        <h2 class="mb-0 h4 fw-bold text-primary">Nouveau Contrat</h2>
                        <p class="text-muted mb-0 small">Saisie des contrats-cadres et mandats</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <form action="ajouter_contrat.php" method="POST" enctype="multipart/form-data">
                        
                        <h5 class="text-primary fw-bold"><i class="bi bi-info-circle me-2"></i>Informations Générales</h5>
                        <hr class="mt-2 mb-4">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Mandat / Projet</label>
                                <select class="form-select" name="num_mandat">
                                    <option value="" selected disabled>Choisir un projet...</option>
                                    <?php foreach ($projets as $p): ?>
                                        <option value="<?= htmlspecialchars($p['nom']) ?>"><?= htmlspecialchars($p['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
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
                            <div class="col-md-3 mb-3">
                                <label class="form-label">N° de contrat</label>
                                <input type="text" class="form-control" name="num_contrat" placeholder="Ex: SC-2026-001">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Type de contrat</label>
                                <select class="form-select" name="type_contrat" id="select_type_contrat" onchange="toggleNouveauType()">
                                    <option value="" selected disabled>Choisir un type...</option>
                                    <option value="Contrat-cadre">Contrat-cadre</option>
                                    <option value="Prestation de services">Prestation de services</option>
                                    <option value="Travaux">Travaux</option>
                                    <option value="Autre">-- Autre (Saisir) --</option>
                                </select>
                                <div id="div_nouveau_type" class="mt-2" style="display: none;">
                                    <input type="text" class="form-control border-primary" name="nouveau_type" placeholder="Précisez le type...">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Objet du contrat <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="objet_contrat" rows="2" required placeholder="Description détaillée du contrat..."></textarea>
                        </div>

                        <h5 class="text-primary fw-bold mt-4"><i class="bi bi-person-badge me-2"></i>Fournisseur & Finances</h5>
                        <hr class="mt-2 mb-4">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nom_fournisseur" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Représentant légal</label>
                                <input type="text" class="form-control" name="representant_legal" placeholder="Nom du représentant">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mode de sélection</label>
                                <input type="text" class="form-control" name="mode_selection" placeholder="Ex: Appel d'offres, Gré à gré...">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3"><label class="form-label">Montant HT</label><input type="number" step="any" class="form-control" name="montant_ht"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Montant Max Annuel</label><input type="number" step="any" class="form-control" name="montant_max_annuel"></div>
                            <div class="col-md-3 mb-3"><label class="form-label fw-bold text-success">Paiement effectué</label><input type="number" step="any" class="form-control border-success" name="paiement_effectue" value="0"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Modalités de paiement</label><input type="text" class="form-control" name="modalites_paiement" placeholder="Ex: Virement à 30 jours"></div>
                        </div>
                        
                        <h5 class="text-primary fw-bold mt-4"><i class="bi bi-calendar-event me-2"></i>Validité & Documents</h5>
                        <hr class="mt-2 mb-4">
                        <div class="row">
                            <div class="col-md-3 mb-3"><label class="form-label">Date de Début</label><input type="date" class="form-control" id="date_debut" name="date_debut"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Date de Fin Prévue</label><input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Durée</label><input type="text" class="form-control bg-light" id="duree_previsionnelle" name="duree_previsionnelle" readonly></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Reconduction tacite</label>
                                <select class="form-select" name="reconduction_tacite">
                                    <option value="">Non précisé</option>
                                    <option value="Oui">Oui</option>
                                    <option value="Non">Non</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label">Avenant / Changement</label><input type="text" class="form-control" name="avenant_changement" placeholder="Description"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Date avenant</label><input type="date" class="form-control" name="date_avenant"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Date fin avenant</label><input type="date" class="form-control" name="date_fin_avenant"></div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Scan du Contrat (Signé) <span class="text-danger">*</span></label>
                                <input type="file" class="form-control shadow-sm" name="fichier_contrat" accept=".pdf,.doc,.docx" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Avenant (optionnel)</label>
                                <input type="file" class="form-control shadow-sm" name="fichier_avenant" accept=".pdf,.doc,.docx">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Statut Actuel</label>
                                <select class="form-select" name="statut">
                                    <option value="En cours" selected>En cours</option>
                                    <option value="Expiré">Expiré</option>
                                    <option value="Clôturé">Clôturé</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date de fin effective</label>
                                <input type="date" class="form-control" name="date_fin_effective" placeholder="Si clôturé">
                            </div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label class="form-label">Observations / Notes particulières</label>
                            <textarea class="form-control" name="observations" rows="2"></textarea>
                        </div>

                        <div class="text-end mt-5">
                            <a href="contrat_dashboard.php" class="btn btn-light border px-4 me-2">Annuler</a>
                            <button type="submit" name="submit_contrat" class="btn btn-primary px-5 fw-bold shadow">
                                <i class="bi bi-check-lg me-2"></i>Enregistrer le Contrat
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // Gestion du type de contrat "Autre"
    function toggleNouveauType() {
        const select = document.getElementById('select_type_contrat');
        const divNouveau = document.getElementById('div_nouveau_type');
        if (select.value === 'Autre') {
            divNouveau.style.display = 'block';
            divNouveau.querySelector('input').setAttribute('required', 'required');
        } else {
            divNouveau.style.display = 'none';
            divNouveau.querySelector('input').removeAttribute('required');
        }
    }

    // Calcul automatique de la durée
    document.addEventListener('DOMContentLoaded', function() {
        const dateDebutInput = document.getElementById('date_debut');
        const dateFinInput = document.getElementById('date_fin_prevue');
        const dureeInput = document.getElementById('duree_previsionnelle');

        function calculerDuree() {
            const dateDebut = new Date(dateDebutInput.value);
            const dateFin = new Date(dateFinInput.value);

            if (dateDebutInput.value && dateFinInput.value && dateFin > dateDebut) {
                let months = (dateFin.getFullYear() - dateDebut.getFullYear()) * 12 + (dateFin.getMonth() - dateDebut.getMonth());
                let years = Math.floor(months / 12);
                let remainingMonths = months % 12;

                let text = "";
                if (years > 0) text += years + (years > 1 ? " ans " : " an ");
                if (remainingMonths > 0) text += remainingMonths + " mois";
                dureeInput.value = text.trim() || "Moins d'un mois";
            } else {
                dureeInput.value = "";
            }
        }
        dateDebutInput.addEventListener('change', calculerDuree);
        dateFinInput.addEventListener('change', calculerDuree);
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>