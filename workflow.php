<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['logisticien', 'comptable'])) {
    header('Location: login.php');
    exit();
}
$utilisateur_nom = $_SESSION['user_nom'] ?? 'Utilisateur';

// --- REQUÊTE SQL AVANCÉE POUR LE WORKFLOW ---
function get_workflow_data($pdo) {
    try {
        // Cette requête complexe joint toutes les tables pour obtenir une vue complète de chaque dossier
        $sql = "
            SELECT 
                b.id AS besoin_id,
                b.titre AS besoin_titre,
                b.date_soumission,
                b.statut AS besoin_statut,
                u.nom AS demandeur_nom,
                dp.id AS demande_proforma_id,
                dp.delai_reponse,
                m.id AS marche_id,
                m.montant AS marche_montant,
                (SELECT COUNT(*) FROM proformas_recus pr WHERE pr.demande_proforma_id = dp.id) AS proformas_recues_count,
                (SELECT COUNT(*) FROM documents_commande dc WHERE dc.marche_id = m.id AND dc.type_document = 'PV') > 0 AS pv_existe,
                (SELECT COUNT(*) FROM documents_commande dc WHERE dc.marche_id = m.id AND dc.type_document = 'Bon de Commande') > 0 AS bc_existe
            FROM besoins b
            LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id
            LEFT JOIN demandes_proforma dp ON b.id = dp.besoin_id
            LEFT JOIN marches m ON b.id = m.besoin_id
            WHERE b.statut NOT IN ('Paiement Approuvé', 'Rejeté par Comptable', 'Rejeté')
            ORDER BY b.date_soumission DESC
        ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // En cas d'erreur, on retourne un tableau vide et on pourrait logger l'erreur
        error_log("SQL Error: " . $e->getMessage());
        return [];
    }
}

$dossiers = get_workflow_data($pdo);

// Fonction pour déterminer le statut principal du workflow
function get_workflow_etape($dossier) {
    if ($dossier['bc_existe']) return ['label' => 'Bon de commande', 'badge' => 'bg-secondary-subtle text-secondary'];
    if ($dossier['pv_existe']) return ['label' => 'PV dépouillement', 'badge' => 'bg-info-subtle text-info'];
    if ($dossier['proformas_recues_count'] > 0) return ['label' => 'Proformas reçues', 'badge' => 'bg-success-subtle text-success'];
    if ($dossier['demande_proforma_id']) return ['label' => 'Attente proforma', 'badge' => 'bg-warning-subtle text-warning'];
    return ['label' => 'Nouveau besoin', 'badge' => 'bg-primary-subtle text-primary'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Besoin - Workflow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom"><h5 class="mb-1">Suivi des Besoins</h5></div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link" href="logisticien.php"><i class="bi bi-house me-2"></i>Tableau de bord</a></li>
            </ul>
            <div class="mt-4">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item mb-1"><a class="nav-link" href="besoins_logisticien.php"><i class="bi bi-clipboard-check me-2"></i>Besoins reçus</a></li>
                    <li class="nav-item mb-1"><a class="nav-link" href="demande_proforma.php"><i class="bi bi-box me-2"></i>Demandes proforma</a></li>
                    <li class="nav-item mb-1"><a class="nav-link" href="marches.php"><i class="bi bi-briefcase me-2"></i>Gérer les marchés</a></li>
                    <li class="nav-item mb-1"><a class="nav-link active" href="workflow.php"><i class="bi bi-diagram-3 me-2"></i>Workflow</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Workflow des besoins</h2>
                    <p class="text-muted mb-0 small">Visualisation du processus de passation de marché</p>
                </div>
                   <a href="logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <?php if (empty($dossiers)): ?>
                <div class="alert alert-info">Aucun dossier en cours de traitement.</div>
            <?php else: ?>
                <?php foreach ($dossiers as $dossier): 
                    $etape = get_workflow_etape($dossier);
                ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($dossier['besoin_titre']) ?></h5>
                                    <p class="text-muted small mb-0">
                                        <code><?= htmlspecialchars($dossier['besoin_id']) ?></code> • Demandeur: <?= htmlspecialchars($dossier['demandeur_nom'] ?? 'N/A') ?>
                                    </p>
                                </div>
                                <div class="text-end">
                                    <?php if($dossier['marche_montant']): ?>
                                        <h5 class="mb-0"><?= number_format($dossier['marche_montant'], 0, ',', ' ') ?> cfa</h5>
                                    <?php endif; ?>
                                    <small class="text-muted">Créé le <?= date('d/m/Y', strtotime($dossier['date_soumission'])) ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="badge fs-6 <?= $etape['badge'] ?>"><?= $etape['label'] ?></span>
                                    <?php if ($etape['label'] == 'Attente proforma' && $dossier['delai_reponse']): ?>
                                        <div class="text-muted small"><i class="bi bi-clock me-1"></i>Délai: <?= date('d/m/Y', strtotime($dossier['delai_reponse'])) ?></div>
                                    <?php elseif ($etape['label'] == 'Proformas reçues'): ?>
                                        <span class="badge bg-secondary"><?= $dossier['proformas_recues_count'] ?> proformas reçues</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <?php if ($etape['label'] == 'Nouveau besoin'): ?>
                                        <a href="demande_proforma.php" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Créer demande proforma</a>
                                    <?php elseif ($etape['label'] == 'Attente proforma' || $etape['label'] == 'Proformas reçues'): ?>
                                        <a href="gerer_reponses.php?id=<?= $dossier['demande_proforma_id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Gérer les réponses</a>
                                    <?php elseif ($etape['label'] == 'PV dépouillement' || $etape['label'] == 'Bon de commande'): ?>
                                        <a href="gerer_marche.php?id=<?= $dossier['marche_id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil-square me-1"></i>Gérer le marché</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>