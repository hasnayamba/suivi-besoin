<?php
session_start();
include 'db_connect.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}

$ao_id = $_GET['ao_id'] ?? null;
if (!$ao_id) {
    $_SESSION['error'] = "ID d'appel d'offres manquant.";
    header('Location: suivi_ao.php');
    exit();
}

try {
    // 1. Récupérer l'Appel d'Offres et le Besoin lié
    $stmt_ao = $pdo->prepare("
        SELECT ao.*, b.titre AS besoin_titre, b.description AS besoin_description
        FROM appels_offre ao
        JOIN besoins b ON ao.besoin_id = b.id
        WHERE ao.id = ?
    ");
    $stmt_ao->execute([$ao_id]);
    $ao = $stmt_ao->fetch();

    if (!$ao) {
        $_SESSION['error'] = "Appel d'offres introuvable.";
        header('Location: suivi_ao.php');
        exit();
    }

    // 2. Récupérer le Marché lié (s'il existe)
    $stmt_marche = $pdo->prepare("SELECT * FROM marches WHERE ao_id = ?");
    $stmt_marche->execute([$ao_id]);
    $marche = $stmt_marche->fetch();
    $marche_id = $marche['id'] ?? null;

    // 3. Récupérer les Documents du marché (s'ils existent)
    $documents = [];
    if ($marche_id) {
        $stmt_docs = $pdo->prepare("
            SELECT * FROM documents_commande 
            WHERE marche_id = ? 
            ORDER BY FIELD(type_document, 'PV', 'Contrat', 'Bon de Commande', 'Bon de Livraison', 'Facture')
        ");
        $stmt_docs->execute([$marche_id]);
        $documents = $stmt_docs->fetchAll();
    }

} catch (PDOException $e) {
    die("Erreur de chargement des détails : " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de l'Appel d'Offres</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="d-flex vh-100">
    <?php include 'header.php'; // Votre sidebar ?>
    <div class="flex-fill d-flex flex-column main-content">
        <header class="bg-white border-bottom px-4 py-3">
             <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Détails de l'Appel d'Offres</h2>
                    <p class="text-muted mb-0 small">Dossier: <?= htmlspecialchars($ao['besoin_titre']) ?></p>
                </div>
                <a href="suivi_ao.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour</a>
            </div>
        </header>

        <main class="flex-fill overflow-auto p-4">
            <div class="row">
                <!-- Colonne de gauche -->
                <div class="col-md-5">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">1. Appel d'Offres</h5></div>
                        <div class="card-body">
                            <p><strong>ID Appel d'Offres :</strong> <code><?= htmlspecialchars($ao['id']) ?></code></p>
                            <p><strong>Canal de Publication :</strong> <?= htmlspecialchars($ao['canal_publication']) ?></p>
                            <p><strong>Date Limite de Soumission :</strong> <?= date('d/m/Y', strtotime($ao['date_limite'])) ?></p>
                            <p><strong>Statut :</strong> <span class="badge bg-primary"><?= htmlspecialchars($ao['statut']) ?></span></p>
                            <a href="uploads/<?= rawurlencode($ao['dossier_ao']) ?>" class="btn btn-outline-primary" download><i class="bi bi-download me-1"></i> Télécharger le DAO</a>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">Besoin Initial</h5></div>
                        <div class="card-body">
                            <p><strong>Titre :</strong> <?= htmlspecialchars($ao['besoin_titre']) ?></p>
                            <p><strong>Description :</strong> <?= htmlspecialchars($ao['besoin_description']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Colonne de droite -->
                <div class="col-md-7">
                    <?php if ($marche): ?>
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">2. Marché Attribué</h5></div>
                        <div class="card-body">
                            <p><strong>ID Marché :</strong> <code><?= htmlspecialchars($marche['id']) ?></code></p>
                            <p><strong>Fournisseur Attribué :</strong> <?= htmlspecialchars($marche['fournisseur']) ?></p>
                            <p><strong>Montant Final :</strong> <?= $marche['montant'] ? number_format($marche['montant'], 0, ',', ' ') . ' cfa' : 'Non spécifié' ?></p>
                            <p><strong>Statut du Marché :</strong> <span class="badge bg-success"><?= htmlspecialchars($marche['statut']) ?></span></p>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">3. Documents du Dossier</h5></div>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($documents)): ?>
                                <li class="list-group-item text-muted">Aucun document n'a encore été ajouté pour ce marché.</li>
                            <?php else: foreach ($documents as $doc): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <strong><?= htmlspecialchars($doc['type_document']) ?></strong>
                                    <a href="uploads/<?= rawurlencode($doc['fichier_joint']) ?>" class="btn btn-sm btn-outline-secondary" download><i class="bi bi-download"></i> Télécharger</a>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <h5 class="alert-heading">En attente de dépouillement</h5>
                        <p>Aucun marché n'a encore été créé pour cet appel d'offres. Utilisez le bouton "Gérer" pour sélectionner un gagnant.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>