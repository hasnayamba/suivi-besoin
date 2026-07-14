<?php
session_start();
include 'db_connect.php';

// --- FONCTION GLOBALE DE TRAÇABILITÉ (PISTE D'AUDIT) ---
if (!function_exists('enregistrer_log')) {
    function enregistrer_log($pdo, $utilisateur_id, $module, $action, $description) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO journal_activites (utilisateur_id, module, action, description, adresse_ip) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$utilisateur_id, $module, $action, $description, $ip]);
        } catch (PDOException $e) {
            error_log("Erreur d'enregistrement du log : " . $e->getMessage());
        }
    }
}

// --- 1. SÉCURITÉ & VÉRIFICATION DU RÔLE ---
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['dp', 'admin'])) {
    header('Location: login.php');
    exit();
}

$utilisateur_nom = $_SESSION['user_nom'] ?? 'Direction';

// --- 2. TRAITEMENT DU COMMENTAIRE / DIRECTIVE DP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_commentaire'])) {
    $dossier_id = $_POST['dossier_id'];
    $module = $_POST['module'];
    $commentaire = trim($_POST['commentaire']);
    
    if (!empty($commentaire)) {
        try {
            $pdo->beginTransaction();
            
            // Insérer le commentaire
            $stmt = $pdo->prepare("INSERT INTO commentaires_direction (dossier_id, module, commentaire, auteur_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$dossier_id, $module, $commentaire, $_SESSION['user_id']]);
            
            // Déterminer les destinataires selon le module et le statut
            $destinataires_ids = [];
            $lien_notif = "#";
            
            if ($module === 'Besoins') {
                // Récupérer le besoin et son statut
                $stmt_b = $pdo->prepare("SELECT statut, utilisateur_id FROM besoins WHERE id = ?");
                $stmt_b->execute([$dossier_id]);
                $besoin = $stmt_b->fetch(PDO::FETCH_ASSOC);
                if ($besoin) {
                    $statut = $besoin['statut'];
                    $role_cible = null;
                    // Logique de routage selon le statut
                    if (stripos($statut, 'Finance') !== false) {
                        $role_cible = 'finance';
                    } elseif (stripos($statut, 'logistique') !== false || stripos($statut, 'proforma') !== false) {
                        $role_cible = 'logisticien';
                    } elseif (stripos($statut, 'validation') !== false) {
                        $role_cible = 'dp'; // direction (éviter auto-notification)
                    } elseif (stripos($statut, 'Correction') !== false) {
                        $role_cible = 'chef_projet';
                    } else {
                        $role_cible = 'admin';
                    }
                    if ($role_cible && $role_cible !== 'dp') {
                        $stmt_users = $pdo->prepare("SELECT id FROM utilisateurs WHERE role = ?");
                        $stmt_users->execute([$role_cible]);
                        $destinataires_ids = $stmt_users->fetchAll(PDO::FETCH_COLUMN);
                    }
                    $lien_notif = "view_besoin.php?id=$dossier_id";
                }
            } elseif ($module === 'Contrats') {
                // Notifier le logisticien ou le responsable du contrat
                $stmt_c = $pdo->prepare("SELECT responsable_id FROM contrats WHERE num_contrat = ? OR id = ?");
                $stmt_c->execute([$dossier_id, $dossier_id]);
                $contrat = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if ($contrat && $contrat['responsable_id']) {
                    $destinataires_ids = [$contrat['responsable_id']];
                } else {
                    $stmt_users = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'logisticien'");
                    $destinataires_ids = $stmt_users->fetchAll(PDO::FETCH_COLUMN);
                }
                $lien_notif = "detail_contrat.php?id=$dossier_id";
            } elseif ($module === 'Conventions') {
                // Notifier le finance ou comptable
                $stmt_users = $pdo->query("SELECT id FROM utilisateurs WHERE role IN ('finance', 'comptable')");
                $destinataires_ids = $stmt_users->fetchAll(PDO::FETCH_COLUMN);
                $lien_notif = "detail_convention.php?id=$dossier_id";
            }
            
            // Insérer les notifications
            if (!empty($destinataires_ids)) {
                $stmt_notif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, lien) VALUES (?, ?, ?)");
                $message = "Directive de la Direction sur $module #$dossier_id : " . substr($commentaire, 0, 100);
                foreach ($destinataires_ids as $dest_id) {
                    $stmt_notif->execute([$dest_id, $message, $lien_notif]);
                }
            }
            
            enregistrer_log($pdo, $_SESSION['user_id'], $module, 'Directive DP', "A laissé une directive sur le dossier $dossier_id : " . substr($commentaire, 0, 50) . "...");
            
            $pdo->commit();
            $_SESSION['success'] = "Votre directive a été ajoutée et notifiée aux responsables.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de l'ajout du commentaire : " . $e->getMessage();
        }
    }
    header('Location: super_admin_dashboard.php');
    exit();
}

// --- 3. RÉCUPÉRATION DES KPI (INDICATEURS CLÉS) ---
$metrics = [];
try {
    $metrics['besoins_attente'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut LIKE '%attente%'")->fetchColumn();
    $metrics['besoins_valides'] = $pdo->query("SELECT COUNT(*) FROM besoins WHERE statut = 'Validé'")->fetchColumn();
    $metrics['contrats_actifs'] = $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'En cours'")->fetchColumn();
    $metrics['proformas_cours'] = $pdo->query("SELECT COUNT(*) FROM demandes_proforma WHERE statut != 'Validé'")->fetchColumn();
    
    $budget_marches = $pdo->query("SELECT SUM(montant) FROM marches WHERE statut = 'En cours'")->fetchColumn() ?: 0;
    $budget_contrats = $pdo->query("SELECT SUM(montant_ht) FROM contrats WHERE statut = 'En cours'")->fetchColumn() ?: 0;
    $metrics['budget_global_engage'] = $budget_marches + $budget_contrats;
} catch (PDOException $e) { 
    error_log("Erreur KPI : " . $e->getMessage()); 
}

// --- 4. RÉCUPÉRATION DES DONNÉES DES TABLEAUX ---
$stmt_logs = $pdo->query("
    SELECT j.*, u.nom, u.role, u.antenne 
    FROM journal_activites j
    JOIN utilisateurs u ON j.utilisateur_id = u.id
    ORDER BY j.date_action DESC LIMIT 100
");
$logs = $stmt_logs->fetchAll();

$queryBesoins = "SELECT b.*, u.nom as emetteur, p.nom as projet_nom 
                 FROM besoins b 
                 LEFT JOIN utilisateurs u ON b.utilisateur_id = u.id 
                 LEFT JOIN projets p ON b.projet_id = p.id
                 ORDER BY b.date_soumission DESC LIMIT 50";
$besoins = $pdo->query($queryBesoins)->fetchAll();

// Contrats : on sélectionne explicitement les colonnes, notamment le numéro de contrat
$contrats = $pdo->query("SELECT id, num_contrat, fichier_contrat, nom_fournisseur, statut, date_creation FROM contrats ORDER BY date_creation DESC LIMIT 100")->fetchAll();
$conventions = $pdo->query("SELECT * FROM conventions ORDER BY date_creation DESC LIMIT 100")->fetchAll();

// Fonction de badge de localisation (compatible PHP < 8.0)
function ouEstLeDossier($statut) {
    $badges = [
        'En attente de Finance' => '<span class="badge bg-info text-dark"><i class="bi bi-calculator"></i> Finance</span>',
        'En attente de la logistique' => '<span class="badge bg-warning text-dark"><i class="bi bi-box-seam"></i> Logistique</span>',
        'En cours de proforma' => '<span class="badge bg-warning text-dark"><i class="bi bi-box-seam"></i> Logistique</span>',
        'En attente de validation' => '<span class="badge bg-primary"><i class="bi bi-person-badge"></i> Direction</span>',
        'Validé' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> En Exécution</span>',
        'Appel d\'offres lancé' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> En Exécution</span>',
        'Marché attribué' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> En Exécution</span>'
    ];
    return $badges[$statut] ?? '<span class="badge bg-secondary"><i class="bi bi-archive"></i> Terminé</span>';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour de Contrôle | Swisscontact</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .clickable-row { cursor: pointer; transition: all 0.2s; }
        .clickable-row:hover { background-color: #e9ecef !important; transform: translateX(2px); }
        .card-kpi { border-radius: 12px; transition: transform 0.3s; }
        .card-kpi:hover { transform: translateY(-3px); }
    </style>
</head>
<body>

<div class="d-flex flex-column vh-100">
    <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <h3 class="h5 mb-0 fw-bold text-primary"><i class="bi bi-eye-fill me-2"></i>Tour de Contrôle - Swisscontact</h3>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-muted me-3">Connecté : <strong class="badge bg-primary fs-6"><?= htmlspecialchars($utilisateur_nom ?? '') ?></strong></span>
            <?php if(strtolower($_SESSION['role']) === 'admin'): ?>
                <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-gear me-1"></i>Retour IT</a>
            <?php endif; ?>
            <a href="deconnexion.php" class="btn btn-sm btn-danger"><i class="bi bi-power"></i></a>
        </div>
    </header>

    <main class="container-fluid p-4 flex-fill overflow-auto">
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-kpi border-0 shadow-sm border-start border-warning border-4 h-100">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small fw-bold">Besoins en souffrance</h6>
                        <h3 class="mb-0 text-warning"><?= $metrics['besoins_attente'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-kpi border-0 shadow-sm border-start border-info border-4 h-100">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small fw-bold">Besoins Validés</h6>
                        <h3 class="mb-0 text-info"><?= $metrics['besoins_valides'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-kpi border-0 shadow-sm border-start border-primary border-4 h-100">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small fw-bold">Dossiers Achats Actifs</h6>
                        <h3 class="mb-0 text-primary"><?= $metrics['proformas_cours'] + $metrics['contrats_actifs'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-kpi border-0 shadow-sm bg-dark text-white border-start border-success border-4 h-100">
                    <div class="card-body">
                        <h6 class="text-white-50 text-uppercase small fw-bold">Budget Global Engagé</h6>
                        <h3 class="mb-0 text-success"><?= number_format($metrics['budget_global_engage'], 0, ',', ' ') ?> <small class="fs-6">FCFA</small></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header bg-white pt-3 pb-0 border-bottom">
                        <ul class="nav nav-tabs card-header-tabs" id="superTab" role="tablist">
                            <li class="nav-item"><button class="nav-link active fw-bold text-primary" data-bs-toggle="tab" data-bs-target="#tab-journal"><i class="bi bi-activity me-1"></i>Piste d'Audit</button></li>
                            <li class="nav-item"><button class="nav-link fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#tab-besoins"><i class="bi bi-cart me-1"></i>Besoins</button></li>
                            <li class="nav-item"><button class="nav-link fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#tab-contrats"><i class="bi bi-file-earmark-text me-1"></i>Contrats</button></li>
                            <li class="nav-item"><button class="nav-link fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#tab-conventions"><i class="bi bi-bank me-1"></i>Conventions</button></li>
                        </ul>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="tab-content" id="superTabContent">
                            
                            <div class="tab-pane fade show active p-3" id="tab-journal">
                                <div class="table-responsive">
                                    <table id="tableLogs" class="table table-hover align-middle mb-0 w-100">
                                        <thead class="table-light text-muted small text-uppercase">
                                            <tr>
                                                <th>Horodatage</th>
                                                <th>Agent</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($logs as $log): ?>
                                                <tr>
                                                    <td data-sort="<?= $log['date_action'] ?? '' ?>" style="min-width: 120px;">
                                                        <span class="d-block small text-muted"><?= date('d/m/Y', strtotime($log['date_action'] ?? 'now')) ?></span>
                                                        <strong class="text-dark"><?= date('H:i:s', strtotime($log['date_action'] ?? 'now')) ?></strong>
                                                    </td>
                                                    <td>
                                                        <strong class="d-block"><?= htmlspecialchars($log['nom'] ?? '') ?></strong>
                                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($log['role'] ?? '') ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $color = match(strtolower($log['action'] ?? '')) {
                                                                'validation', 'création' => 'text-success',
                                                                'rejet', 'suppression', 'clôture' => 'text-danger',
                                                                'modification', 'directive dp' => 'text-warning',
                                                                default => 'text-primary'
                                                            };
                                                        ?>
                                                        <span class="badge bg-secondary mb-1"><?= htmlspecialchars($log['module'] ?? '') ?></span><br>
                                                        <span class="<?= $color ?> fw-bold me-1">[<?= htmlspecialchars($log['action'] ?? '') ?>]</span>
                                                        <span class="small"><?= htmlspecialchars($log['description'] ?? '') ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tab-besoins">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light text-muted small">
                                            <tr><th>Réf</th><th>Objet</th><th>Localisation</th><th class="text-end pe-3">Action</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($besoins as $b): ?>
                                            <!-- On ouvre view_besoin.php pour chaque besoin. Attention : ce fichier doit gérer les deux types (achat direct et TDR) -->
                                            <tr class="clickable-row" onclick="window.open('super_voir_besoin.php?id=<?= urlencode($b['id']) ?>', '_blank')"
                                                <td><small class="text-muted fw-bold"><?= htmlspecialchars($b['id'] ?? '') ?></small></td>
                                                <td><?= htmlspecialchars($b['titre'] ?? '') ?></td>
                                                <td><?= ouEstLeDossier($b['statut'] ?? '') ?></td>
                                                <td class="text-end pe-3" onclick="event.stopPropagation();">
                                                    <button class="btn btn-sm btn-outline-primary shadow-sm" title="Laisser une directive" 
                                                            onclick="ouvrirModaleCommentaire('<?= htmlspecialchars($b['id'] ?? '') ?>', 'Besoins', '<?= htmlspecialchars(addslashes($b['titre'] ?? '')) ?>')">
                                                        <i class="bi bi-chat-text-fill"></i> Note
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tab-contrats">
                               <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light text-muted small">
                                            <tr><th>N° Contrat</th><th>Fournisseur</th><th>Statut</th><th class="text-end pe-3">Action</th></td>
                                        </thead>
                                        <tbody>
                                            <?php foreach($contrats as $c): ?>
                                            <tr class="clickable-row" onclick="window.open('viewer.php?file=<?= urlencode($c['fichier_contrat'] ?? '') ?>', '_blank')">
                                                <td><small class="text-muted fw-bold"><?= htmlspecialchars($c['num_contrat'] ?? 'Non défini') ?></small></td>
                                                <td><strong><?= htmlspecialchars($c['nom_fournisseur'] ?? '') ?></strong></td>
                                                <td><span class="badge <?= ($c['statut'] ?? '')=='En cours'?'bg-success':(($c['statut'] ?? '')=='Expiré'?'bg-danger':'bg-secondary') ?>"><?= htmlspecialchars($c['statut'] ?? '') ?></span></td>
                                                <td class="text-end pe-3" onclick="event.stopPropagation();">
                                                    <button class="btn btn-sm btn-outline-primary shadow-sm" title="Laisser une directive" 
                                                            onclick="ouvrirModaleCommentaire('<?= htmlspecialchars($c['id'] ?? '') ?>', 'Contrats', 'Contrat - <?= htmlspecialchars(addslashes($c['nom_fournisseur'] ?? '')) ?>')">
                                                        <i class="bi bi-chat-text-fill"></i> Note
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                               </div>
                            </div>

                            <div class="tab-pane fade" id="tab-conventions">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light text-muted small">
                                            <tr><th>N° Conv</th><th>Partenaire</th><th>Solde Restant</th><th class="text-end pe-3">Action</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($conventions as $conv): ?>
                                            <tr class="clickable-row" onclick="window.open('viewer.php?file=<?= urlencode($conv['fichier_convention'] ?? '') ?>', '_blank')">
                                                <td><small class="text-muted fw-bold"><?= htmlspecialchars($conv['num_convention'] ?? '') ?></small></td>
                                                <td><strong><?= htmlspecialchars($conv['nom_partenaire'] ?? '') ?></strong></td>
                                                <td class="text-danger fw-bold"><?= number_format($conv['solde_restant'] ?? 0, 0, ',', ' ') ?> CFA</td>
                                                <td class="text-end pe-3" onclick="event.stopPropagation();">
                                                    <button class="btn btn-sm btn-outline-primary shadow-sm" title="Laisser une directive" 
                                                            onclick="ouvrirModaleCommentaire('<?= htmlspecialchars($conv['num_convention'] ?? '') ?>', 'Conventions', 'Convention - <?= htmlspecialchars(addslashes($conv['nom_partenaire'] ?? '')) ?>')">
                                                        <i class="bi bi-chat-text-fill"></i> Note
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- MODALE COMMENTAIRE -->
<div class="modal fade" id="modalCommentaire" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-primary shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-chat-quote-fill me-2"></i>Émettre une Directive</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-primary bg-primary bg-opacity-10 border-0 py-2 small mb-3">
                        Dossier ciblé : <strong id="modal_dossier_nom"></strong> (<span id="modal_dossier_id_display" class="text-primary fw-bold"></span>)
                    </div>
                    
                    <input type="hidden" name="dossier_id" id="input_dossier_id">
                    <input type="hidden" name="module" id="input_module">
                    
                    <div class="mb-2">
                        <label class="form-label fw-bold">Votre instruction / commentaire :</label>
                        <textarea class="form-control" name="commentaire" rows="4" placeholder="Tapez votre message ici..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="ajouter_commentaire" class="btn btn-primary fw-bold">
                        <i class="bi bi-send-fill me-2"></i>Envoyer au journal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#tableLogs').DataTable({
            "order": [[ 0, "desc" ]],
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.7/i18n/fr-FR.json" },
            "pageLength": 10,
            "info": false,
            "lengthChange": false
        });
    });

    function ouvrirModaleCommentaire(id, module, titre) {
        document.getElementById('modal_dossier_id_display').innerText = id;
        document.getElementById('modal_dossier_nom').innerText = titre;
        document.getElementById('input_dossier_id').value = id;
        document.getElementById('input_module').value = module;
        
        var myModal = new bootstrap.Modal(document.getElementById('modalCommentaire'));
        myModal.show();
    }
</script>

</body>
</html>