<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// Fonction d'upload sécurisée
function uploadFile($file, $prefix) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'png'];
        if (in_array($ext, $allowed)) {
            // Nom unique : PREFIXE_TIMESTAMP_RANDOM.ext
            $newName = $prefix . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            $dest = __DIR__ . '/uploads/' . $newName;
            
            // Créer le dossier s'il n'existe pas
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
    
    // Validation du fichier obligatoire
    if (empty($_FILES['fichier_contrat']['name'])) {
        $_SESSION['error'] = "Le fichier du contrat est obligatoire.";
    } else {
        try {
            // Upload des fichiers
            $fichier_contrat = uploadFile($_FILES['fichier_contrat'], 'CONTRAT');
            $fichier_avenant = uploadFile($_FILES['fichier_avenant'], 'AVENANT');

            if (!$fichier_contrat) {
                throw new Exception("Erreur lors du téléchargement du contrat (Format non supporté ou erreur serveur).");
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
            
            // Fonction pour transformer une chaîne vide en NULL
            $emptyToNull = function($value) {
                return $value === '' ? null : $value;
            };

            $stmt->execute([
                ':num_mandat' => $emptyToNull($_POST['num_mandat']),
                ':antenne' => $emptyToNull($_POST['antenne']),
                ':mode_selection' => $emptyToNull($_POST['mode_selection']),
                ':nom_fournisseur' => $_POST['nom_fournisseur'], // Obligatoire
                ':representant_legal' => $emptyToNull($_POST['representant_legal']),
                ':type_contrat' => $emptyToNull($_POST['type_contrat']),
                ':num_contrat' => $emptyToNull($_POST['num_contrat']),
                ':objet_contrat' => $_POST['objet_contrat'], // Obligatoire
                ':montant_max_annuel' => $emptyToNull($_POST['montant_max_annuel']),
                ':montant_ht' => $emptyToNull($_POST['montant_ht']),
                ':paiement_effectue' => $emptyToNull($_POST['paiement_effectue']), // Nouveau champ
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
                ':fichier_contrat' => $fichier_contrat, // Nouveau champ
                ':fichier_avenant' => $fichier_avenant  // Nouveau champ
            ]);
            
            $_SESSION['success'] = "Le contrat pour " . htmlspecialchars($_POST['nom_fournisseur']) . " a été créé avec succès.";
            header('Location: contrat_dashboard.php');
            exit();
            
        } catch (Exception $e) { // Capture Exception générale pour attraper aussi l'erreur d'upload
            $_SESSION['error'] = "Erreur : " . $e->getMessage();
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
                <div>
                    <h2 class="mb-1">Ajouter un Nouveau Contrat</h2>
                    <p class="text-muted mb-0 small">Remplir les informations du contrat-cadre ou mandat</p>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body p-4">
                    <form action="ajouter_contrat.php" method="POST" enctype="multipart/form-data">
                        
                        <h5 class="text-primary">Informations Générales</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-3 mb-3"><label class="form-label">Mandat</label><input type="text" class="form-control" name="num_mandat"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Antenne</label><input type="text" class="form-control" name="antenne"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">N° de contrat</label><input type="text" class="form-control" name="num_contrat"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Type de contrat</label><input type="text" class="form-control" name="type_contrat"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Objet du contrat <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="objet_contrat" rows="3" required></textarea>
                        </div>

                        <h5 class="text-primary mt-4">Fournisseur & Sélection</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label">Nom du Fournisseur/Prestataire <span class="text-danger">*</span></label><input type="text" class="form-control" name="nom_fournisseur" required></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Représentant légal</label><input type="text" class="form-control" name="representant_legal"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Mode de sélection</label><input type="text" class="form-control" name="mode_selection"></div>
                        </div>

                        <h5 class="text-primary mt-4">Finances</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label">Montant Contrat (TTC/Cadre)</label><input type="number" class="form-control" name="montant_ht" placeholder="en cfa"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Montant Max Annuel (TTC)</label><input type="number" class="form-control" name="montant_max_annuel" placeholder="en cfa"></div>
                            <div class="col-md-4 mb-3"><label class="form-label fw-bold">Paiement Effectué</label><input type="number" class="form-control border-success" name="paiement_effectue" placeholder="en cfa" value="0"></div>
                        </div>
                        
                        <h5 class="text-primary mt-4">Durée & Statut</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label">Début ou date de signature</label><input type="date" class="form-control" id="date_debut" name="date_debut"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Fin du contrat (prévue)</label><input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue"></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Durée (prévisionnelle)</label><input type="text" class="form-control" id="duree_previsionnelle" name="duree_previsionnelle" readonly></div>
                        </div>
                         <div class="row">
                             <div class="col-md-4 mb-3"><label class="form-label">Statut</label><select class="form-select" name="statut"><option value="En cours" selected>En cours</option><option value="Expiré">Expiré</option><option value="Cloturé">Cloturé</option></select></div>
                             <div class="col-md-4 mb-3"><label class="form-label">Date de fin contrat (effective)</label><input type="date" class="form-control" name="date_fin_effective"></div>
                        </div>
                        
                        <h5 class="text-primary mt-4">Avenant & Reconduction</h5>
                        <hr class="mt-2">
                         <div class="row">
                            <div class="col-md-3 mb-3"><label class="form-label">Si reconduction (tacite)...</label><input type="text" class="form-control" name="reconduction_tacite" placeholder="Oui/Non, mode et préavis..."></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Avenant et changement</label><input type="text" class="form-control" name="avenant_changement"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Date de l'avenant</label><input type="date" class="form-control" name="date_avenant"></div>
                            <div class="col-md-3 mb-3"><label class="form-label">Date de fin avenant</label><input type="date" class="form-control" name="date_fin_avenant"></div>
                        </div>

                        <h5 class="text-primary mt-4">Documents & Divers</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fichier Contrat (PDF/DOC) <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="fichier_contrat" accept=".pdf,.doc,.docx" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fichier Avenant (Optionnel)</label>
                                <input type="file" class="form-control" name="fichier_avenant" accept=".pdf,.doc,.docx">
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label">Modalités de paiement</label><textarea class="form-control" name="modalites_paiement" rows="3"></textarea></div>
                        <div class="mb-3"><label class="form-label">Observations</label><textarea class="form-control" name="observations" rows="3"></textarea></div>

                        <div class="text-end mt-4">
                            <a href="contrat_dashboard.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" name="submit_contrat" class="btn btn-primary">Enregistrer le contrat</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // SCRIPT POUR LE CALCUL AUTOMATIQUE DE LA DURÉE
    document.addEventListener('DOMContentLoaded', function() {
        const dateDebutInput = document.getElementById('date_debut');
        const dateFinInput = document.getElementById('date_fin_prevue');
        const dureeInput = document.getElementById('duree_previsionnelle');

        function calculerDuree() {
            const dateDebut = new Date(dateDebutInput.value);
            const dateFin = new Date(dateFinInput.value);

            if (dateDebutInput.value && dateFinInput.value && dateFin > dateDebut) {
                let years = dateFin.getFullYear() - dateDebut.getFullYear();
                let months = dateFin.getMonth() - dateDebut.getMonth();
                let days = dateFin.getDate() - dateDebut.getDate();

                if (days < 0) {
                    months--;
                    const dernierJourMoisPrecedent = new Date(dateFin.getFullYear(), dateFin.getMonth(), 0).getDate();
                    days += dernierJourMoisPrecedent;
                }
                if (months < 0) {
                    years--;
                    months += 12;
                }

                let dureeTexte = "";
                if (years > 0) dureeTexte += years + (years > 1 ? " ans " : " an ");
                if (months > 0) dureeTexte += months + " mois ";
                if (days > 0) dureeTexte += days + (days > 1 ? " jours" : " jour");
                
                dureeInput.value = dureeTexte.trim() || "0 jours";
            } else {
                dureeInput.value = "";
            }
        }
        dateDebutInput.addEventListener('change', calculerDuree);
        dateFinInput.addEventListener('change', calculerDuree);
    });
</script>
</body>
</html>