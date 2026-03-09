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
    header('Location: convention_dashboard.php');
    exit();
}

// Récupération de la convention
try {
    $stmt = $pdo->prepare("SELECT * FROM conventions WHERE id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch();

    if (!$c) {
        throw new Exception("Convention introuvable.");
    }
} catch (Exception $e) {
    error_log("Erreur convention détail: " . $e->getMessage());
    header('Location: convention_dashboard.php?error=not_found');
    exit();
}

// Fonctions helper
function formatMoney($amount) {
    return $amount ? number_format($amount, 0, ',', ' ') . ' CFA' : '-';
}

function formatDate($date) {
    return $date ? date('d/m/Y', strtotime($date)) : '-';
}

function getSafeFileUrl($filename) {
    if (!$filename) return null;
    return 'uploads/' . rawurlencode(basename($filename));
}

// Calcul du statut financier (OPTION 3)
$montant_global = (float)($c['montant_global'] ?? 0);
$paiements_effectues = (float)($c['paiements_effectues'] ?? 0);
$solde_restant = (float)($c['solde_restant'] ?? 0);

$statut_financier = match(true) {
    $solde_restant <= 0 => ['badge' => 'bg-success', 'text' => 'Intégralité payée'],
    $paiements_effectues == 0 => ['badge' => 'bg-danger', 'text' => 'Aucun paiement'],
    default => ['badge' => 'bg-warning', 'text' => 'Paiement en cours']
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Convention - <?= htmlspecialchars($c['nom_partenaire']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Styles pour l'affichage "Papier" à l'écran */
        body { 
            background-color: #f0f0f0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content { 
            padding: 0 !important; 
        }
        .page-container {
            max-width: 21cm;
            min-height: 29.7cm;
            margin: 2rem auto;
            padding: 2cm;
            background: white;
            box-shadow: 0 0 15px rgba(0,0,0,.1);
            border-radius: 8px;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .label-detail { 
            font-weight: 600; 
            color: #555; 
            font-size: 0.9rem; 
            margin-bottom: 4px;
        }
        .value-detail { 
            color: #000; 
            font-size: 1rem; 
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
        
        /* Styles pour les badges de statut */
        .status-badge {
            font-size: 0.85rem;
            padding: 6px 12px;
        }
        
        /* Styles spécifiques pour l'impression */
        @media print {
            .no-print { 
                display: none !important; 
            }
            
            body { 
                background-color: white !important; 
                margin: 0 !important; 
                padding: 0 !important;
                font-size: 12pt;
            }
            .page-container {
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

            /* Optimisation des grilles pour l'impression */
            .row > * {
                float: left;
            }
            .col-print-3 { width: 25%; }
            .col-print-4 { width: 33.3333%; }
            .col-print-6 { width: 50%; }
            .col-print-12 { width: 100%; }

            /* Supprimer les éléments interactifs */
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
            .page-container {
                margin: 1rem;
                padding: 1rem;
                max-width: calc(100% - 2rem);
            }
            .main-content {
                overflow-x: hidden;
            }
        }
    </style>
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <div class="no-print">
        <?php include 'sidebar_convention.php'; ?>
    </div>

    <div class="flex-fill d-flex flex-column main-content h-100 overflow-auto">
        <header class="bg-white border-bottom px-4 py-3 no-print">
             <div class="d-flex justify-content-between align-items-center">
                 <div>
                    <h2 class="mb-1">Fiche Convention</h2>
                    <p class="text-muted mb-0 small">Consultation des détails</p>
                 </div>
                 <div class="d-flex gap-2">
                    <button onclick="window.print()" class="btn btn-outline-primary">
                        <i class="bi bi-printer me-2"></i>Imprimer
                    </button>
                    <a href="convention_dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Retour
                    </a>
                 </div>
             </div>
        </header>

        <main class="p-4 flex-fill">
            <div class="page-container">
                
                <!-- EN-TÊTE OFFICIEL POUR IMPRESSION -->
                <div class="print-header">
                    <h1 class="text-uppercase fw-bold">FICHE CONVENTION N°<?= htmlspecialchars($c['num_convention'] ?? 'N/A') ?></h1>
                    <p class="subtitle">Système de Gestion des Conventions - Généré le <?= date('d/m/Y à H:i') ?></p>
                </div>
                
                <div class="card bg-transparent">
                    
                    <!-- EN-TÊTE DE LA FICHE -->
                    <div class="card-header bg-white p-4 border-bottom">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h3 class="mb-2 text-primary"><?= htmlspecialchars($c['nom_partenaire']) ?></h3>
                                <p class="text-muted mb-0"><?= htmlspecialchars($c['objet_convention']) ?></p>
                            </div>
                            <div class="col-md-4 text-end">
                                <!-- Statut général de la convention -->
                                <span class="badge bg-primary status-badge mb-2">
                                    <?= htmlspecialchars($c['statut']) ?>
                                </span>
                                <br>
                                <!-- Statut financier (OPTION 3) -->
                                <span class="badge <?= $statut_financier['badge'] ?> status-badge">
                                    <?= $statut_financier['text'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        
                        <!-- SECTION : Informations Générales -->
                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i> Informations Générales</h5>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">N° Mandat</div>
                                <div class="value-detail"><?= htmlspecialchars($c['num_mandat'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">N° Convention</div>
                                <div class="value-detail fw-bold"><?= htmlspecialchars($c['num_convention'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Type de Convention</div>
                                <div class="value-detail"><?= htmlspecialchars($c['type_convention'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Antenne</div>
                                <div class="value-detail"><?= htmlspecialchars($c['antenne'] ?? '-') ?></div>
                            </div>
                        </div>

                        <!-- SECTION : Partenaire -->
                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i> Partenaire</h5>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 col-print-4 mb-3">
                                <div class="label-detail">Nom du Partenaire</div>
                                <div class="value-detail fw-bold"><?= htmlspecialchars($c['nom_partenaire']) ?></div>
                            </div>
                            <div class="col-md-4 col-print-4 mb-3">
                                <div class="label-detail">Représentant Légal</div>
                                <div class="value-detail"><?= htmlspecialchars($c['representant_legal'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-4 col-print-4 mb-3">
                                <div class="label-detail">Mode de Sélection</div>
                                <div class="value-detail"><?= htmlspecialchars($c['mode_selection'] ?? '-') ?></div>
                            </div>
                        </div>

                        <!-- SECTION : Finances & Durée -->
                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-currency-dollar me-2"></i> Finances & Durée</h5>
                        </div>
                        
                        <!-- Barre de progression du paiement -->
                        <div class="row mb-3 no-print">
                            <div class="col-12">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Progression du paiement</small>
                                    <small>
                                        <?= $montant_global > 0 ? round(($paiements_effectues / $montant_global) * 100, 1) : 0 ?>%
                                        (<?= formatMoney($paiements_effectues) ?> / <?= formatMoney($montant_global) ?>)
                                    </small>
                                </div>
                                <div class="progress-container">
                                    <div class="progress-bar" style="width: <?= $montant_global > 0 ? ($paiements_effectues / $montant_global) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Montant Global</div>
                                <div class="value-detail text-success fw-bold"><?= formatMoney($c['montant_global']) ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Paiements Effectués</div>
                                <div class="value-detail text-primary fw-bold"><?= formatMoney($paiements_effectues) ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Solde Restant</div>
                                <div class="value-detail text-danger fw-bold"><?= formatMoney($solde_restant) ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Périodicité de Paiement</div>
                                <div class="value-detail"><?= htmlspecialchars($c['periodicite_paiement'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Date Début</div>
                                <div class="value-detail"><?= formatDate($c['date_debut']) ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Date Fin</div>
                                <div class="value-detail"><?= formatDate($c['date_fin']) ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Durée</div>
                                <div class="value-detail"><?= htmlspecialchars($c['duree'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3 col-print-4 mb-3">
                                <div class="label-detail">Intervention / Appui</div>
                                <div class="value-detail"><?= htmlspecialchars($c['intervention_appui'] ?? '-') ?></div>
                            </div>
                        </div>

                        <!-- SECTION : Détails & Avenants -->
                        <div class="section-title mb-4">
                            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i> Détails & Avenants</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-12 col-print-12 mb-3">
                                <div class="label-detail">Modalités de Paiement</div>
                                <div class="value-detail border rounded p-3 bg-light">
                                    <?= nl2br(htmlspecialchars($c['modalites_paiement'] ?? 'Non spécifié')) ?>
                                </div>
                            </div>
                             <div class="col-md-6 col-print-6 mb-3">
                                <div class="label-detail">Avenant / Changement</div>
                                <div class="value-detail"><?= nl2br(htmlspecialchars($c['avenant_changement'] ?? 'Aucun avenant')) ?></div>
                            </div>
                            <div class="col-md-6 col-print-6 mb-3">
                                <div class="label-detail">Observations Générales</div>
                                <div class="value-detail border rounded p-3 bg-light">
                                    <?= nl2br(htmlspecialchars($c['observations'] ?? 'Aucune observation')) ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SECTION : Documents Joints -->
                         <div class="section-title mb-4 mt-4">
                            <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i> Documents Joints</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-6 col-print-6 mb-3">
                                <div class="label-detail">Convention Signée</div>
                                <?php if($c['fichier_convention']): 
                                    $fileUrl = getSafeFileUrl($c['fichier_convention']);
                                ?>
                                    <div class="value-detail">
                                        <a href="<?= $fileUrl ?>" class="btn btn-sm btn-outline-primary me-2 no-print" download>
                                            <i class="bi bi-download me-1"></i> Télécharger
                                        </a>
                                        <span class="text-success fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>Document joint
                                        </span>
                                        <span class="d-none d-print-inline ms-2">
                                            (<?= htmlspecialchars($c['fichier_convention']) ?>)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="value-detail text-danger">
                                        <i class="bi bi-x-circle me-1"></i>Aucun document joint
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 col-print-6 mb-3">
                                <div class="label-detail">Avenant</div>
                                <?php if($c['fichier_avenant']): 
                                    $fileUrl = getSafeFileUrl($c['fichier_avenant']);
                                ?>
                                    <div class="value-detail">
                                        <a href="<?= $fileUrl ?>" class="btn btn-sm btn-outline-primary me-2 no-print" download>
                                            <i class="bi bi-download me-1"></i> Télécharger
                                        </a>
                                        <span class="text-success fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>Avenant joint
                                        </span>
                                        <span class="d-none d-print-inline ms-2">
                                            (<?= htmlspecialchars($c['fichier_avenant']) ?>)
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
                            Fiche générée par le système de gestion des conventions le <?= date('d/m/Y à H:i') ?>
                            <?= $c['date_creation'] ? ' | Créée le ' . formatDate($c['date_creation']) : '' ?>
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
    // Optimisation avant impression
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

// Auto-sélection pour l'impression
function optimizeForPrint() {
    document.querySelectorAll('.col-md-3').forEach(el => {
        el.classList.add('col-print-4');
    });
    document.querySelectorAll('.col-md-4').forEach(el => {
        el.classList.add('col-print-4');
    });
    document.querySelectorAll('.col-md-6').forEach(el => {
        el.classList.add('col-print-6');
    });
}

optimizeForPrint();
</script>
</body>
</html>