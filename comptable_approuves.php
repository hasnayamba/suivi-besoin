<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'comptable') { 
    header('Location: login.php'); 
    exit(); 
}

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Comptable';

// --- TRAITEMENT AJAX DE LA MISE À JOUR DU MODE DE PAIEMENT ---
if (isset($_POST['update_mode'])) {
    $marche_id = $_POST['marche_id'];
    $mode = $_POST['mode'];
    $stmt = $pdo->prepare("UPDATE marches SET mode_paiement = ? WHERE id = ?");
    $stmt->execute([$mode, $marche_id]);
    exit('success'); // On arrête le script ici pour l'appel AJAX
}

// --- REQUÊTE SQL MODIFIÉE (On récupère mode_paiement) ---
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

function trouver_preuve($chaine_docs) {
    if (empty($chaine_docs)) return null;
    $docs = explode('||', $chaine_docs);
    foreach ($docs as $doc) {
        $parts = explode('::', $doc);
        if (count($parts) < 2) continue;
        $type = strtolower(trim($parts[0]));
        if (strpos($type, 'preuve') !== false || strpos($type, 'virement') !== false || strpos($type, 'paiement') !== false) {
            return trim($parts[1]);
        }
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique des Paiements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .btn-check:checked + .btn-outline-primary { background-color: #0d6efd; color: white; }
        .mode-paiement-group .btn { font-size: 0.75rem; padding: 2px 8px; }
    </style>
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
                <h2 class="mb-0 fw-bold text-success">Historique des Paiements</h2>
                <span class="badge bg-light text-dark border p-2"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($utilisateur_nom) ?></span>
            </div>
        </header>

        <main class="p-4">
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-4">Dossier</th>
                                <th>Bénéficiaire</th>
                                <th>Montant</th>
                                <th>Mode de Paiement</th> <th class="text-center">Justificatif</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($marches_approuves as $marche): 
                                $fichier_preuve = trouver_preuve($marche['tous_les_docs']);
                                $m_id = $marche['id'];
                                $current_mode = $marche['mode_paiement'];
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold"><?= htmlspecialchars($marche['titre']) ?></div>
                                    <small class="text-muted">ID: <?= $m_id ?></small>
                                </td>
                                <td><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                <td class="fw-bold text-success"><?= number_format($marche['montant'], 0, ',', ' ') ?> CFA</td>
                                
                                <td>
                                    <div class="btn-group mode-paiement-group" role="group">
                                        <input type="radio" class="btn-check" name="mode_<?= $m_id ?>" id="chq_<?= $m_id ?>" autocomplete="off" 
                                               onclick="updatePaymentMode(<?= $m_id ?>, 'Chèque')" <?= $current_mode == 'Chèque' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary" for="chq_<?= $m_id ?>">Chèque</label>

                                        <input type="radio" class="btn-check" name="mode_<?= $m_id ?>" id="ov_<?= $m_id ?>" autocomplete="off" 
                                               onclick="updatePaymentMode(<?= $m_id ?>, 'Virement')" <?= $current_mode == 'Virement' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary" for="ov_<?= $m_id ?>">Virement</label>

                                        <input type="radio" class="btn-check" name="mode_<?= $m_id ?>" id="caisse_<?= $m_id ?>" autocomplete="off" 
                                               onclick="updatePaymentMode(<?= $m_id ?>, 'Caisse')" <?= $current_mode == 'Caisse' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary" for="caisse_<?= $m_id ?>">Caisse</label>
                                    </div>
                                </td>

                                <td class="text-center">
                                    <?php if ($fichier_preuve): ?>
                                        <a href="uploads/<?= $fichier_preuve ?>" target="_blank" class="text-danger fs-5"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                    <?php else: ?>
                                        <span class="text-muted small">Non joint</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="dossier_validation.php?besoin_id=<?= $marche['besoin_id'] ?>" class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// Fonction AJAX pour sauvegarder le choix sans recharger la page
function updatePaymentMode(marcheId, modeValeur) {
    const formData = new FormData();
    formData.append('update_mode', '1');
    formData.append('marche_id', marcheId);
    formData.append('mode', modeValeur);

    fetch('comptable_approuves.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        if(data === 'success') {
            console.log("Mode mis à jour : " + modeValeur);
        }
    })
    .catch(error => alert("Erreur de sauvegarde"));
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>