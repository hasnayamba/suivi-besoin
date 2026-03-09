<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') { 
    header('Location: login.php'); 
    exit(); 
}

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';
$utilisateur_email = $_SESSION['user_email'] ?? 'email@example.com';

// --- REQUÊTE SQL "TOUT-TERRAIN" ---
// On utilise GROUP_CONCAT pour récupérer TOUS les documents du marché en une seule fois
// Format : "Type1::Fichier1||Type2::Fichier2"
$sql = "
    SELECT m.*, 
           GROUP_CONCAT(CONCAT(d.type_document, '::', d.fichier_joint) SEPARATOR '||') as tous_les_docs
    FROM marches m 
    LEFT JOIN documents_commande d ON m.id = d.marche_id
    WHERE m.statut = 'Paiement Approuvé' 
    GROUP BY m.id
    ORDER BY m.date_paiement DESC, m.date_debut DESC
";
$marches_approuves = $pdo->query($sql)->fetchAll();

// --- FONCTION POUR TROUVER LA PREUVE DANS LA LISTE ---
function trouver_preuve($chaine_docs) {
    if (empty($chaine_docs)) return null;
    
    $docs = explode('||', $chaine_docs);
    $candidat = null;

    foreach ($docs as $doc) {
        // On sépare le Type du Fichier
        $parts = explode('::', $doc);
        if (count($parts) < 2) continue;
        
        $type = strtolower(trim($parts[0])); // ex: "preuve de paiement"
        $fichier = trim($parts[1]);

        // 1. Priorité absolue : Si le type contient ces mots clés
        if (strpos($type, 'preuve') !== false || 
            strpos($type, 'virement') !== false || 
            strpos($type, 'paiement') !== false || 
            strpos($type, 'cheque') !== false || 
            strpos($type, 'reçu') !== false) {
            return $fichier;
        }
        
        // 2. Sinon, on garde le dernier document qui n'est PAS une facture/BC/BL/Contrat/PV
        // (C'est souvent la preuve ajoutée à la fin)
        if (!in_array($type, ['facture', 'bon de commande', 'bon de livraison', 'contrat', 'pv', 'proforma', 'dossier client'])) {
            $candidat = $fichier;
        }
    }
    
    return $candidat;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Paiements - Comptabilité</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <nav class="sidebar bg-white border-end" style="width: 260px;">
        <div class="p-4 border-bottom">
            <h5 class="mb-1 text-primary fw-bold">SWISSCONTACT</h5>
            <small class="text-muted fw-bold text-uppercase">Comptabilité</small>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_dashboard.php"><i class="bi bi-grid-1x2 me-2"></i>Tableau de bord</a></li>
                <li class="nav-item mb-1"><a class="nav-link active fw-bold" href="comptable_approuves.php"><i class="bi bi-check2-circle me-2"></i>Dossiers Approuvés</a></li>
                <li class="nav-item mb-1"><a class="nav-link text-dark" href="comptable_rejetes.php"><i class="bi bi-x-circle me-2"></i>Dossiers Rejetés</a></li>
            </ul>
        </div>
    </nav>

    <div class="flex-fill d-flex flex-column main-content overflow-auto">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold text-success"><i class="bi bi-patch-check-fill me-2"></i>Historique des Paiements</h2>
                    <p class="text-muted mb-0 small">Dossiers liquidés et archivés</p>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center border" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-2 text-primary"></i><span class="fw-bold"><?= htmlspecialchars($utilisateur_nom) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li class="px-3 py-2">
                            <div class="fw-bold text-dark"><?= htmlspecialchars($utilisateur_nom) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($utilisateur_email) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="deconnexion.php">Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="p-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">ID Dossier</th>
                                    <th>Titre du Dossier</th>
                                    <th>Bénéficiaire</th>
                                    <th>Date Paiement</th>
                                    <th>Montant Payé</th>
                                    <th class="text-center">Preuve</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($marches_approuves)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-3"></i>Aucun dossier approuvé pour le moment.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($marches_approuves as $marche): 
                                        // On utilise la fonction PHP pour chercher intelligemment la preuve
                                        $fichier_preuve = trouver_preuve($marche['tous_les_docs']);
                                    ?>
                                    <tr>
                                        <td class="ps-4"><code class="text-dark bg-light px-2 py-1 rounded border"><?= htmlspecialchars($marche['id']) ?></code></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($marche['titre']) ?></td>
                                        <td><i class="bi bi-shop text-muted me-2"></i><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                        <td>
                                            <?php 
                                            // Priorité à la date de paiement réelle
                                            $date_ref = !empty($marche['date_paiement']) && $marche['date_paiement'] != '0000-00-00' 
                                                        ? $marche['date_paiement'] 
                                                        : $marche['date_debut'];
                                            echo date('d/m/Y', strtotime($date_ref)); 
                                            ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success bg-success bg-opacity-10 px-2 py-1 rounded border border-success border-opacity-25">
                                                <?php 
                                                // Priorité au montant réellement payé
                                                $montant_final = ($marche['montant_paye'] > 0) ? $marche['montant_paye'] : $marche['montant'];
                                                echo number_format($montant_final, 0, ',', ' '); 
                                                ?> CFA
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($fichier_preuve): ?>
                                                <a href="uploads/<?= rawurlencode($fichier_preuve) ?>" target="_blank" class="btn btn-sm btn-danger shadow-sm rounded-pill px-3" title="Voir le justificatif">
                                                    <i class="bi bi-file-earmark-pdf-fill me-1"></i> Voir
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small" title="Aucun document trouvé">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="dossier_validation.php?besoin_id=<?= $marche['besoin_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-eye"></i> Détails
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>