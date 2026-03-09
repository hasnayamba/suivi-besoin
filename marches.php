<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

// --- FONCTIONS ---
function get_historique_marches($pdo) {
    try {
        $sql = "SELECT * FROM marches ORDER BY date_debut DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function get_marche_status_badge($statut) {
    $map = [
        'En cours' => 'bg-primary',
        'Facturé' => 'bg-info text-dark',
        'Validé' => 'bg-success',
        'Annulé' => 'bg-danger',
    ];
    $class = $map[$statut] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}

$marches = get_historique_marches($pdo);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Marchés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex vh-100">
    <?php
    include 'header.php';
    ?>

    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Gestion des Marchés</h2>
                    <p class="text-muted mb-0 small">Liste de tous les marchés créés et en cours</p>
                </div>
                   <a href="logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Tous les marchés</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID Marché</th>
                                    <th>Titre</th>
                                    <th>Fournisseur</th>
                                    <th>Montant</th>
                                    <th>Date début</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($marches)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Aucun marché n'a été créé pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($marches as $marche): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($marche['id']) ?></code></td>
                                        <td><?= htmlspecialchars($marche['titre']) ?></td>
                                        <td><?= htmlspecialchars($marche['fournisseur']) ?></td>
                                        <td><?= number_format($marche['montant'], 0, ',', ' ') . ' cfa' ?></td>
                                        <td><?= date('d/m/Y', strtotime($marche['date_debut'])) ?></td>
                                        <td><?= get_marche_status_badge($marche['statut']) ?></td>
                                        <td class="text-end">
                                            <a href="gerer_marche.php?id=<?= htmlspecialchars($marche['id']) ?>" class="btn btn-sm btn-outline-primary">
                                                Gérer
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