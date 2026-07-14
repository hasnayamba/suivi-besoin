<?php
// view_besoin.php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['dp', 'admin'])) {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? '';
if (empty($id)) {
    die("Besoin non spécifié.");
}

// Récupérer le besoin
$stmt = $pdo->prepare("SELECT * FROM besoins WHERE id = ?");
$stmt->execute([$id]);
$besoin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$besoin) {
    die("Besoin introuvable.");
}

$type = $besoin['type_demande'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Détail du besoin - <?= htmlspecialchars($besoin['id']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; padding: 20px; }
        .card { border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .toolbar { background: #fff; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="toolbar">
    <h5><i class="bi bi-file-earmark-text"></i> Besoin <?= htmlspecialchars($besoin['id']) ?> – <?= htmlspecialchars($besoin['titre']) ?></h5>
    <a href="javascript:window.close()" class="btn btn-sm btn-secondary">Fermer</a>
</div>

<div class="container">
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <strong>Informations générales</strong>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6"><strong>Type :</strong> <?= $type === 'Achat_Direct' ? 'Achat direct (Fourniture)' : 'TDR / CDC' ?></div>
                <div class="col-md-6"><strong>Statut :</strong> <?= htmlspecialchars($besoin['statut']) ?></div>
                <div class="col-md-6"><strong>Montant :</strong> <?= number_format($besoin['montant'], 0, ',', ' ') ?> FCFA</div>
                <div class="col-md-6"><strong>Date :</strong> <?= date('d/m/Y', strtotime($besoin['date_soumission'])) ?></div>
            </div>
            <?php if (!empty($besoin['description'])): ?>
                <hr>
                <strong>Description :</strong><br><?= nl2br(htmlspecialchars($besoin['description'])) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($type === 'Achat_Direct'): ?>
        <div class="card">
            <div class="card-header bg-success text-white">
                <strong>Articles demandés</strong>
            </div>
            <div class="card-body">
                <?php
                $stmtArt = $pdo->prepare("SELECT * FROM besoin_articles WHERE besoin_id = ?");
                $stmtArt->execute([$id]);
                $articles = $stmtArt->fetchAll();
                if (count($articles) > 0):
                ?>
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr><th>Désignation</th><th>Unité</th><th>Quantité</th><th>PU (CFA)</th><th>Total (CFA)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['designation']) ?></td>
                            <td><?= htmlspecialchars($a['unite']) ?></td>
                            <td><?= $a['quantite'] ?></td>
                            <td><?= number_format($a['pu_indicatif'], 0, ',', ' ') ?></td>
                            <td><?= number_format($a['prix_total'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="alert alert-warning">Aucun article enregistré pour ce besoin.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header bg-info text-white">
                <strong>Document attaché (TDR/CDC)</strong>
            </div>
            <div class="card-body">
                <?php if (!empty($besoin['fichier'])): ?>
                    <iframe src="viewer.php?file=<?= urlencode($besoin['fichier']) ?>" style="width:100%; height:600px; border:none;"></iframe>
                <?php else: ?>
                    <div class="alert alert-danger">Aucun fichier trouvé pour ce besoin.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>