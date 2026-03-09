<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- RÉCUPÉRATION DE L'ID ---
$contrat_id = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$contrat_id) {
    $_SESSION['error'] = "Identifiant de contrat manquant.";
    header('Location: contrat_dashboard.php');
    exit();
}

// Fonction d'upload (identique à ajouter_contrat.php)
function uploadFile($file, $prefix) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'png'];
        if (in_array($ext, $allowed)) {
            $newName = $prefix . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            $dest = __DIR__ . '/uploads/' . $newName;
            if (!is_dir(__DIR__ . '/uploads/')) { mkdir(__DIR__ . '/uploads/', 0755, true); }
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                return $newName;
            }
        }
    }
    return null;
}

// --- TRAITEMENT DU FORMULAIRE (MISE À JOUR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_update'])) {
    
    try {
        // Gestion des fichiers : on garde l'ancien si pas de nouveau chargé
        $fichier_contrat = $_POST['old_fichier_contrat'];
        if (!empty($_FILES['fichier_contrat']['name'])) {
            $newC = uploadFile($_FILES['fichier_contrat'], 'CONTRAT');
            if ($newC) {
                // Supprimer l'ancien fichier physique si nécessaire
                if ($fichier_contrat && file_exists(__DIR__ . '/uploads/' . $fichier_contrat)) {
                    unlink(__DIR__ . '/uploads/' . $fichier_contrat);
                }
                $fichier_contrat = $newC;
            }
        }

        $fichier_avenant = $_POST['old_fichier_avenant'];
        if (!empty($_FILES['fichier_avenant']['name'])) {
            $newA = uploadFile($_FILES['fichier_avenant'], 'AVENANT');
            if ($newA) {
                 if ($fichier_avenant && file_exists(__DIR__ . '/uploads/' . $fichier_avenant)) {
                    unlink(__DIR__ . '/uploads/' . $fichier_avenant);
                }
                $fichier_avenant = $newA;
            }
        }

        $sql = "UPDATE contrats SET 
                    num_mandat = :num_mandat,
                    antenne = :antenne,
                    mode_selection = :mode_selection,
                    nom_fournisseur = :nom_fournisseur,
                    representant_legal = :representant_legal,
                    type_contrat = :type_contrat,
                    num_contrat = :num_contrat,
                    objet_contrat = :objet_contrat,
                    montant_max_annuel = :montant_max_annuel,
                    montant_ht = :montant_ht,
                    paiement_effectue = :paiement_effectue,
                    date_debut = :date_debut,
                    date_fin_prevue = :date_fin_prevue,
                    duree_previsionnelle = :duree_previsionnelle,
                    reconduction_tacite = :reconduction_tacite,
                    avenant_changement = :avenant_changement,
                    date_avenant = :date_avenant,
                    date_fin_avenant = :date_fin_avenant,
                    statut = :statut,
                    date_fin_effective = :date_fin_effective,
                    modalites_paiement = :modalites_paiement,
                    observations = :observations,
                    fichier_contrat = :fichier_contrat,
                    fichier_avenant = :fichier_avenant
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        
        $emptyToNull = function($value) {
            return $value === '' ? null : $value;
        };

        $stmt->execute([
            ':num_mandat' => $emptyToNull($_POST['num_mandat']),
            ':antenne' => $emptyToNull($_POST['antenne']),
            ':mode_selection' => $emptyToNull($_POST['mode_selection']),
            ':nom_fournisseur' => $_POST['nom_fournisseur'],
            ':representant_legal' => $emptyToNull($_POST['representant_legal']),
            ':type_contrat' => $emptyToNull($_POST['type_contrat']),
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
            ':fichier_avenant' => $fichier_avenant,
            ':id' => $contrat_id
        ]);
        
        $_SESSION['success'] = "Le contrat a été mis à jour avec succès.";
        header('Location: contrat_dashboard.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
    }
}

// --- RÉCUPÉRATION DES DONNÉES DU CONTRAT ---
$stmt = $pdo->prepare("SELECT * FROM contrats WHERE id = ?");
$stmt->execute([$contrat_id]);
$contrat = $stmt->fetch();

if (!$contrat) {
    $_SESSION['error'] = "Contrat introuvable.";
    header('Location: contrat_dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le Contrat</title>
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
                    <h2 class="mb-1">Modifier le Contrat</h2>
                    <p class="text-muted mb-0 small">Modification des informations du contrat</p>
                </div>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body p-4">
                    <form action="modifier_contrat.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($contrat['id']) ?>">
                        <input type="hidden" name="old_fichier_contrat" value="<?= htmlspecialchars($contrat['fichier_contrat'] ?? '') ?>">
                        <input type="hidden" name="old_fichier_avenant" value="<?= htmlspecialchars($contrat['fichier_avenant'] ?? '') ?>">
                        
                        <h5 class="text-primary">Informations Générales</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="num_mandat" class="form-label">Mandat</label>
                                <input type="text" class="form-control" id="num_mandat" name="num_mandat" value="<?= htmlspecialchars($contrat['num_mandat'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="antenne" class="form-label">Antenne</label>
                                <input type="text" class="form-control" id="antenne" name="antenne" value="<?= htmlspecialchars($contrat['antenne'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="num_contrat" class="form-label">N° de contrat</label>
                                <input type="text" class="form-control" id="num_contrat" name="num_contrat" value="<?= htmlspecialchars($contrat['num_contrat'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="type_contrat" class="form-label">Type de contrat</label>
                                <input type="text" class="form-control" id="type_contrat" name="type_contrat" value="<?= htmlspecialchars($contrat['type_contrat'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="objet_contrat" class="form-label">Objet du contrat <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="objet_contrat" name="objet_contrat" rows="3" required><?= htmlspecialchars($contrat['objet_contrat'] ?? '') ?></textarea>
                        </div>

                        <h5 class="text-primary mt-4">Fournisseur & Sélection</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="nom_fournisseur" class="form-label">Nom du Fournisseur <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nom_fournisseur" name="nom_fournisseur" value="<?= htmlspecialchars($contrat['nom_fournisseur'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="representant_legal" class="form-label">Représentant légal</label>
                                <input type="text" class="form-control" id="representant_legal" name="representant_legal" value="<?= htmlspecialchars($contrat['representant_legal'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="mode_selection" class="form-label">Mode de sélection</label>
                                <input type="text" class="form-control" id="mode_selection" name="mode_selection" value="<?= htmlspecialchars($contrat['mode_selection'] ?? '') ?>">
                            </div>
                        </div>

                        <h5 class="text-primary mt-4">Finances</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="montant_ht" class="form-label">Montant contrat (TTC/Cadre)</label>
                                <input type="number" class="form-control" id="montant_ht" name="montant_ht" value="<?= htmlspecialchars($contrat['montant_ht'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="montant_max_annuel" class="form-label">Montant max annuel TTC</label>
                                <input type="number" class="form-control" id="montant_max_annuel" name="montant_max_annuel" value="<?= htmlspecialchars($contrat['montant_max_annuel'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="paiement_effectue" class="form-label fw-bold">Paiement Effectué</label>
                                <input type="number" class="form-control border-success" id="paiement_effectue" name="paiement_effectue" value="<?= htmlspecialchars($contrat['paiement_effectue'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <h5 class="text-primary mt-4">Durée & Statut</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="date_debut" class="form-label">Début ou date de signature</label>
                                <input type="date" class="form-control" id="date_debut" name="date_debut" value="<?= htmlspecialchars($contrat['date_debut'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="date_fin_prevue" class="form-label">Fin du contrat (prévue)</label>
                                <input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue" value="<?= htmlspecialchars($contrat['date_fin_prevue'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="duree_previsionnelle" class="form-label">Durée (prévisionnelle)</label>
                                <input type="text" class="form-control" id="duree_previsionnelle" name="duree_previsionnelle" value="<?= htmlspecialchars($contrat['duree_previsionnelle'] ?? '') ?>" readonly>
                            </div>
                        </div>
                         <div class="row">
                             <div class="col-md-4 mb-3">
                                <label for="statut" class="form-label">Statut</label>
                                <select class="form-select" id="statut" name="statut">
                                    <option value="En cours" <?= ($contrat['statut'] == 'En cours') ? 'selected' : '' ?>>En cours</option>
                                    <option value="Expiré" <?= ($contrat['statut'] == 'Expiré') ? 'selected' : '' ?>>Expiré</option>
                                    <option value="Cloturé" <?= ($contrat['statut'] == 'Cloturé') ? 'selected' : '' ?>>Cloturé</option>
                                </select>
                            </div>
                             <div class="col-md-4 mb-3">
                                <label for="date_fin_effective" class="form-label">Date de fin contrat (effective)</label>
                                <input type="date" class="form-control" id="date_fin_effective" name="date_fin_effective" value="<?= htmlspecialchars($contrat['date_fin_effective'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <h5 class="text-primary mt-4">Avenant & Reconduction</h5>
                        <hr class="mt-2">
                         <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="reconduction_tacite" class="form-label">Si reconduction (tacite)...</label>
                                <input type="text" class="form-control" id="reconduction_tacite" name="reconduction_tacite" value="<?= htmlspecialchars($contrat['reconduction_tacite'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="avenant_changement" class="form-label">Avenant et changement</label>
                                <input type="text" class="form-control" id="avenant_changement" name="avenant_changement" value="<?= htmlspecialchars($contrat['avenant_changement'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="date_avenant" class="form-label">Date de l'avenant</label>
                                <input type="date" class="form-control" id="date_avenant" name="date_avenant" value="<?= htmlspecialchars($contrat['date_avenant'] ?? '') ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="date_fin_avenant" class="form-label">Date de fin avenant</label>
                                <input type="date" class="form-control" id="date_fin_avenant" name="date_fin_avenant" value="<?= htmlspecialchars($contrat['date_fin_avenant'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <h5 class="text-primary mt-4">Documents & Divers</h5>
                        <hr class="mt-2">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fichier Contrat (Remplacer ?)</label>
                                <?php if (!empty($contrat['fichier_contrat'])): ?>
                                    <div class="mb-2 text-success"><i class="bi bi-check-circle"></i> Fichier actuel : <a href="uploads/<?= rawurlencode($contrat['fichier_contrat']) ?>" target="_blank">Voir</a></div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="fichier_contrat" accept=".pdf,.doc,.docx">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fichier Avenant (Remplacer ?)</label>
                                <?php if (!empty($contrat['fichier_avenant'])): ?>
                                    <div class="mb-2 text-success"><i class="bi bi-check-circle"></i> Fichier actuel : <a href="uploads/<?= rawurlencode($contrat['fichier_avenant']) ?>" target="_blank">Voir</a></div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="fichier_avenant" accept=".pdf,.doc,.docx">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modalites_paiement" class="form-label">Modalités de paiement</label>
                            <textarea class="form-control" id="modalites_paiement" name="modalites_paiement" rows="3"><?= htmlspecialchars($contrat['modalites_paiement'] ?? '') ?></textarea>
                        </div>
                         <div class="mb-3">
                            <label for="observations" class="form-label">Observations</label>
                            <textarea class="form-control" id="observations" name="observations" rows="3"><?= htmlspecialchars($contrat['observations'] ?? '') ?></textarea>
                        </div>

                        <div class="text-end mt-4">
                            <a href="contrat_dashboard.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" name="submit_update" class="btn btn-primary">Enregistrer les modifications</button>
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
                // Calcul de la différence
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
                if (years > 0) {
                    dureeTexte += years + (years > 1 ? " ans " : " an ");
                }
                if (months > 0) {
                    dureeTexte += months + " mois ";
                }
                if (days > 0) {
                    dureeTexte += days + (days > 1 ? " jours" : " jour");
                }
                
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