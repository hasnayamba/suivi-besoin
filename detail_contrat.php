<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: contrat_dashboard.php');
    exit();
}

// Récupération du contrat
try {
    $stmt = $pdo->prepare("SELECT * FROM contrats WHERE id = ?");
    $stmt->execute([$id]);
    $contrat = $stmt->fetch();

    if (!$contrat) {
        throw new Exception("Contrat introuvable.");
    }
} catch (Exception $e) {
    error_log("Erreur contrat détail: " . $e->getMessage());
    header('Location: contrat_dashboard.php?error=not_found');
    exit();
}

// --- CALCUL DU SOLDE RESTANT ---
$montant_ht = (float)($contrat['montant_ht'] ?? 0);
$paiement_effectue = (float)($contrat['paiement_effectue'] ?? 0);
$solde_restant = $montant_ht - $paiement_effectue;

// Fonction de formatage monétaire
function formatMoney($amount) {
    return $amount ? number_format($amount, 0, ',', ' ') . ' CFA' : '-';
}

// Fonction de formatage de date
function formatDate($date) {
    return $date ? date('d/m/Y', strtotime($date)) : '-';
}

// Fonction de sécurisation des URLs de fichiers 
function getSafeFileUrl($filename) {
    if (empty($filename)) return null;
    return 'uploads/' . ltrim($filename, '/');
}


// Déterminer le statut financier 
$statut_financier = match(true) {
    $montant_ht == 0 => ['badge' => 'bg-secondary', 'text' => 'Non défini'],
    $solde_restant <= 0 => ['badge' => 'bg-success', 'text' => 'Payé (Soldé)'],
    $contrat['statut'] === 'Rupture de contrat' => ['badge' => 'bg-dark', 'text' => 'Paiement arrêté (Rupture)'],
    $solde_restant < ($montant_ht * 0.1) => ['badge' => 'bg-warning', 'text' => 'Solde faible'],
    default => ['badge' => 'bg-danger', 'text' => 'En cours de paiement']
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Contrat - <?= htmlspecialchars($contrat['nom_fournisseur']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Styles pour le design "Papier A4" sur écran */
        body { 
            background-color: #f0f0f0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content { 
            padding: 0 !important; 
        }
        .container {
            max-width: 21cm;
            min-height: 29.7cm;
            margin: 2rem auto;
            padding: 2cm;
            background-color: white;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .card {
            border: none;
            box-shadow: none !important;
        }
        .label-detail { 
            font-weight: 600; 
            color: #555; 
            font-size: 0.9em;
            margin-bottom: 4px;
        }
        .value-detail { 
            color: #000; 
            font-size: 1em; 
            margin-bottom: 12px;
            padding: 4px 0;
        }
        .section-title h5 {
            font-weight: 700;
            color: #212529;
            padding-bottom: 8px;
            border-bottom: 2px solid #3b71ca;
            margin-bottom: 1.5rem;
        }
        .print-header { 
            display: none;
        }
        .financial-status {
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
        }
        
        /* Barre de progression pour le paiement */
        .progress-container {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            margin: 8px 0;
        }
        .progress-bar {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s ease;
        }
        .progress-bar.bg-rupture {
            background: linear-gradient(90deg, #343a40, #6c757d) !important;
        }
        
        /* Styles spécifiques pour l'impression */
        @media print {
            .no-print { 
                display: none !important; 
            }
            
            body { 
                background-color: white !important; 
                margin: 0; 
                padding: 0;
                font-size: 12pt;
            }
            .container { 
                max-width: 100% !important; 
                min-height: auto !important;
                margin: 0 !important;
                padding: 1.5cm !important;
                box-shadow: none !important; 
                border-radius: 0 !important;
                page-break-after: always;
            }
            .card { 
                border: none !important; 
            }

            /* Mise en Noir & Blanc */
            .text-primary, .text-secondary, .text-success, 
            .text-danger, .text-muted, .label-detail, 
            .value-detail { 
                color: #000 !important; 
            }
            .section-title h5 { 
                border-bottom: 2px solid #000 !important; 
                color: #000 !important;
            }
            .badge {
                background-color: #ccc !important;
                color: #000 !important;
                border: 1px solid #999;
            }

            /* Afficher l'En-tête Officiel */
            .print-header { 
                display: block !important; 
                margin-bottom: 20px; 
                padding-bottom: 15px; 
                border-bottom: 2px solid #000;
                text-align: center;
            }
            .print-header h1 { 
                font-size: 1.5rem; 
                color: #000 !important; 
                margin-bottom: 5px;
            }
            .print-header .subtitle {
                font-size: 0.9rem;
                color: #666 !important;
            }

            /* Optimisation de l'espace */
            .row > * {
                float: left;
            }
            .col-md-3 { width: 25%; }
            .col-md-4 { width: 33.3333%; }
            .col-md-6 { width: 50%; }
            .col-md-12 { width: 100%; }

            /* Supprimer les ombres et couleurs de fond */
            .card-header, .card-footer {
                 background-color: white !important;
                 border-color: #ddd !important;
            }
            
            /* Cacher les éléments interactifs */
            .btn, .progress-container {
                display: none !important;
            }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 1rem;
                max-width: calc(100% - 2rem);
            }
            .main-content {
                overflow-x: hidden;
            }
            .d-flex.gap-2 {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <div class="no-print">
        <?php include 'sidebar_contrat.php'; ?>
    </div>

    <div class="flex-fill d-flex flex-column main-content h-100 overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 no-print">
             <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                 <div>
                    <h2 class="mb-1 h4 fw-bold">Fiche Contrat</h2>
                    <p class="text-muted mb-0 small">Consultation des détails du contrat</p>
                 </div>
                 
                 <div class="d-flex gap-2">
                    <?php if($contrat['statut'] === 'En cours'): ?>
                        <a href="modifier_contrat.php?id=<?= $contrat['id'] ?>" class="btn btn-outline-primary d-flex align-items-center">
                            <i class="bi bi-pencil me-2"></i>Modifier
                        </a>
                    <?php endif; ?>

                    <?php 
                        if(!in_array($contrat['statut'], ['Cloturé', 'Rupture de contrat'])): 
                            $dateFin = new DateTime($contrat['date_fin_prevue']);
                            $limiteAlerte = new DateTime('+60 days');
                            $estEligibleAction = ($contrat['statut'] === 'Expiré' || ($contrat['statut'] === 'En cours' && $dateFin <= $limiteAlerte));
                            
                            if($estEligibleAction): 
                    ?>
                        <a href="renouveler_contrat.php?id=<?= $contrat['id'] ?>" class="btn btn-outline-warning d-flex align-items-center">
                            <i class="bi bi-arrow-repeat me-2"></i>Renouveler
                        </a>
                        <a href="cloturer_contrat.php?id=<?= $contrat['id'] ?>" class="btn btn-outline-danger d-flex align-items-center">
                            <i class="bi bi-door-closed me-2"></i>Clôturer
                        </a>
                    <?php 
                            endif;
                        endif; 
                    ?>

                    <button onclick="window.print()" class="btn btn-outline-secondary d-flex align-items-center">
                        <i class="bi bi-printer me-2"></i>Imprimer
                    </button>
                    <a href="contrat_dashboard.php" class="btn btn-secondary d-flex align-items-center">
                        <i class="bi bi-arrow-left me-2"></i>Retour
                    </a>
                 </div>
             </div>
        </header>

        <main class="p-4 flex-fill">
            <div class="container">
                
                <div class="print-header">
                    <h1 class="text-uppercase fw-bold">FICHE CONTRAT N°<?= htmlspecialchars($contrat['num_contrat'] ?? 'N/A') ?></h1>
                    <p class="subtitle">Système de Gestion des Contrats - Généré le <?= date('d/m/Y à H:i') ?></p>
                </div>
                
                <div class="card bg-transparent">
                    
                    <div class="card-header bg-white p-4 border-bottom">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h3 class="mb-2 text-primary"><?= htmlspecialchars($contrat['nom_fournisseur']) ?></h3>
                                <p class="text-muted mb-0"><?= htmlspecialchars($contrat['objet_contrat']) ?></p>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php 
                                    $badgeClass = match($contrat['statut']) {
                                        'Expiré' => 'bg-danger',
                                        'Cloturé' => 'bg-secondary',
                                        'Rupture de contrat' => 'bg-dark',
                                        'En cours' => 'bg-success',
                                        default => 'bg-primary'
                                    };
                                ?>
                                <span class="badge <?= $badgeClass ?> fs-6 mb-2"><?= htmlspecialchars($contrat['statut']) ?></span>
                                <br>
                                <span class="badge <?= $statut_financier['badge'] ?> fs-6">
                                    <?= $statut_financier['text'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        
                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i> Informations Générales</h5>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">N° Mandat</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['num_mandat'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">N° Contrat</div>
                                <div class="value-detail fw-bold"><?= htmlspecialchars($contrat['num_contrat'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Type de Contrat</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['type_contrat'] ?? '-') ?></div>
                            </div>
                             <div class="col-md-3 mb-3">
                                <div class="label-detail">Antenne</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['antenne'] ?? '-') ?></div>
                            </div>
                        </div>

                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i> Fournisseur</h5>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <div class="label-detail">Nom du Fournisseur</div>
                                <div class="value-detail fw-bold"><?= htmlspecialchars($contrat['nom_fournisseur']) ?></div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="label-detail">Représentant Légal</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['representant_legal'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="label-detail">Mode de Sélection</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['mode_selection'] ?? '-') ?></div>
                            </div>
                        </div>

                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-currency-dollar me-2"></i> Finances & Durée</h5>
                        </div>
                        
                        <div class="row mb-3 no-print">
                            <div class="col-12">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Progression du paiement</small>
                                    <small><?= $montant_ht > 0 ? round(($paiement_effectue / $montant_ht) * 100, 1) : 0 ?>%</small>
                                </div>
                               <div class="progress-container">
    <?php 
        // Si le contrat est rompu, on applique la classe grise, sinon on laisse le dégradé vert par défaut
        $progressClass = ($contrat['statut'] === 'Rupture de contrat') ? 'bg-rupture' : ''; 
    ?>
    <div class="progress-bar <?= $progressClass ?>" style="width: <?= $montant_ht > 0 ? ($paiement_effectue / $montant_ht) * 100 : 0 ?>%"></div>
</div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Montant Total HT</div>
                                <div class="value-detail text-success fw-bold"><?= formatMoney($contrat['montant_ht']) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Montant Max Annuel</div>
                                <div class="value-detail"><?= formatMoney($contrat['montant_max_annuel']) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Paiement Effectué</div>
                                <div class="value-detail text-primary fw-bold"><?= formatMoney($paiement_effectue) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Solde Restant</div>
                                <div class="value-detail text-danger fw-bold"><?= formatMoney($solde_restant) ?></div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Date Début</div>
                                <div class="value-detail"><?= formatDate($contrat['date_debut']) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Date Fin Prévue</div>
                                <div class="value-detail"><?= formatDate($contrat['date_fin_prevue']) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Date Fin Effective</div>
                                <div class="value-detail"><?= formatDate($contrat['date_fin_effective']) ?></div>
                            </div>
                             <div class="col-md-3 mb-3">
                                <div class="label-detail">Durée Prévue</div>
                                <div class="value-detail"><?= htmlspecialchars($contrat['duree_previsionnelle'] ?? '-') ?></div>
                            </div>
                        </div>

                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i> Détails & Avenants</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <div class="label-detail">Modalités de Paiement</div>
                                <div class="value-detail border rounded p-3 bg-light">
                                    <?= nl2br(htmlspecialchars($contrat['modalites_paiement'] ?? 'Non spécifié')) ?>
                                </div>
                            </div>
                             <div class="col-md-6 mb-3">
                                <div class="label-detail">Avenant / Changement</div>
                                <div class="value-detail"><?= nl2br(htmlspecialchars($contrat['avenant_changement'] ?? 'Aucun avenant')) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Date Avenant</div>
                                <div class="value-detail"><?= formatDate($contrat['date_avenant']) ?></div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="label-detail">Fin Avenant</div>
                                <div class="value-detail"><?= formatDate($contrat['date_fin_avenant']) ?></div>
                            </div>
                            <div class="col-md-12 mt-3">
                                <div class="label-detail">Observations Générales</div>
                                <div class="value-detail border rounded p-3 bg-light">
                                    <?= nl2br(htmlspecialchars($contrat['observations'] ?? 'Aucune observation')) ?>
                                </div>
                            </div>

                            <?php if(!empty($contrat['motif_cloture'])): ?>
                            <div class="col-md-12 mt-3">
                                <div class="label-detail text-danger"><i class="bi bi-exclamation-octagon-fill me-1"></i> Motif de Rupture du Contrat</div>
                                <div class="value-detail border border-danger rounded p-3 bg-white text-danger fw-bold shadow-sm">
                                    <?= nl2br(htmlspecialchars($contrat['motif_cloture'])) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                         <div class="section-title mb-4 mt-4">
                            <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i> Documents Joints</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="label-detail">Contrat Signé</div>
                                <?php if($contrat['fichier_contrat']): 
                                    $fileUrl = getSafeFileUrl($contrat['fichier_contrat']);
                                ?>
                                    <div class="value-detail">
                                        <a href="<?= htmlspecialchars($fileUrl) ?>" class="btn btn-sm btn-outline-primary me-2 no-print" download>
                                            <i class="bi bi-download me-1"></i> Télécharger
                                        </a>
                                        <span class="text-success fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>Document joint
                                        </span>
                                        <span class="d-none d-print-inline ms-2">
                                            (<?= htmlspecialchars($contrat['fichier_contrat']) ?>)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="value-detail text-danger">
                                        <i class="bi bi-x-circle me-1"></i>Aucun document joint
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="label-detail">Avenant</div>
                                <?php if($contrat['fichier_avenant']): 
                                    $fileUrl = getSafeFileUrl($contrat['fichier_avenant']);
                                ?>
                                    <div class="value-detail">
                                        <a href="<?= htmlspecialchars($fileUrl) ?>" class="btn btn-sm btn-outline-primary me-2 no-print" download>
                                            <i class="bi bi-download me-1"></i> Télécharger
                                        </a>
                                        <span class="text-success fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>Avenant joint
                                        </span>
                                        <span class="d-none d-print-inline ms-2">
                                            (<?= htmlspecialchars($contrat['fichier_avenant']) ?>)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="value-detail text-muted">
                                        <i class="bi bi-dash-circle me-1"></i>Aucun avenant joint
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer text-muted text-center py-3 no-print">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Fiche générée par le système de gestion des contrats le <?= date('d/m/Y à H:i') ?>
                        </small>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Script pour améliorer l'impression
document.addEventListener('DOMContentLoaded', function() {
    window.addEventListener('beforeprint', function() {
        document.body.classList.add('printing');
    });
    
    window.addEventListener('afterprint', function() {
        document.body.classList.remove('printing');
    });
});

// Confirmation de téléchargement
document.querySelectorAll('a[download]').forEach(link => {
    link.addEventListener('click', function(e) {
        const fileName = this.href.split('/').pop();
        if (!confirm(`Télécharger le fichier "${decodeURIComponent(fileName)}" ?`)) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>