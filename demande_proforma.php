<?php
session_start();
include 'db_connect.php';

// --- IMPORTATION DE PHPMAILER ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// --- SÉCURITÉ ---
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'logisticien') {
    header('Location: login.php');
    exit();
}
$utilisateur_nom = $_SESSION['user_nom'];

function generateProformaId() {
    return 'DP' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
}

// --- GESTION DE L'AJOUT ET ENVOI D'E-MAILS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proforma'])) {
    $besoin_id = trim($_POST['proformaBesoinId']);
    $delai_reponse = trim($_POST['proformaDelai']);
    $fournisseurs_selectionnes = $_POST['fournisseurs'] ?? [];
    $fichier_nom = null;

    // 1. VÉRIFICATION ANTI-DOUBLON (Très important !)
    $check_statut = $pdo->prepare("SELECT statut FROM besoins WHERE id = ?");
    $check_statut->execute([$besoin_id]);
    if ($check_statut->fetchColumn() !== 'Validé') {
        $_SESSION['error'] = "Action refusée : Une demande a déjà été lancée pour ce besoin ou il n'est plus valide.";
        header('Location: demande_proforma.php');
        exit();
    }

    if (empty($besoin_id) || empty($delai_reponse)) {
        $_SESSION['error'] = "Veuillez sélectionner un besoin et spécifier un délai.";
    } elseif (empty($fournisseurs_selectionnes)) {
        $_SESSION['error'] = "Veuillez sélectionner au moins un fournisseur à contacter.";
    } else {
        // Upload du cahier des charges (Optionnel)
        if (isset($_FILES['proformaFichier']) && $_FILES['proformaFichier']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proformaFichier']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'png', 'jpeg'])) {
                $uploadFileDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
                $newFileName = generateProformaId() . '_TDR.' . $ext;
                if (move_uploaded_file($_FILES['proformaFichier']['tmp_name'], $uploadFileDir . $newFileName)) {
                    $fichier_nom = $newFileName;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            // Récupérer les infos du besoin
            $stmtBesoin = $pdo->prepare("SELECT titre, description FROM besoins WHERE id = :id");
            $stmtBesoin->execute([':id' => $besoin_id]);
            $infos_besoin = $stmtBesoin->fetch(PDO::FETCH_ASSOC);
            $titre_besoin = $infos_besoin['titre'];

            // Créer la demande proforma en BDD
            $proforma_id = generateProformaId();
            $sql = "INSERT INTO demandes_proforma (id, titre_besoin, emetteur, date_emission, delai_reponse, statut, fichier, besoin_id)
                    VALUES (:id, :titre_besoin, :emetteur, CURDATE(), :delai_reponse, 'En attente', :fichier, :besoin_id)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $proforma_id, ':titre_besoin' => $titre_besoin, ':emetteur' => $utilisateur_nom,
                ':delai_reponse' => $delai_reponse, ':fichier' => $fichier_nom, ':besoin_id' => $besoin_id
            ]);

            // Lier les fournisseurs et générer les tokens SECRETS
            $stmt_liaison = $pdo->prepare("INSERT INTO proforma_fournisseurs (proforma_id, fournisseur_id, token) VALUES (?, ?, ?)");
            $fournisseurs_data = []; // Pour garder les infos pour l'e-mail plus tard

            foreach ($fournisseurs_selectionnes as $f_id) {
                $token = bin2hex(random_bytes(16));
                $stmt_liaison->execute([$proforma_id, $f_id, $token]);
                $fournisseurs_data[] = ['id' => $f_id, 'token' => $token];
            }

            // Mettre à jour le statut du besoin global
            $stmtUpdate = $pdo->prepare("UPDATE besoins SET statut = 'En cours de proforma' WHERE id = :id");
            $stmtUpdate->execute([':id' => $besoin_id]);
            
            // =========================================================
            // ON VALIDE LA BASE DE DONNÉES AVANT L'ENVOI D'E-MAIL !
            // =========================================================
            $pdo->commit(); 
            
            // Tentative d'envoi des e-mails
            $emails_envoyes = 0;
            try {
                $mail = new PHPMailer(true);

                $mail->isSMTP();
                $mail->Host       = 'smtp.office365.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = getenv('SMTP_USER');
                $mail->Password   = getenv('SMTP_PASS');
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom(getenv('SMTP_USER'), 'Logistique Swisscontact');
                $mail->SMTPDebug = 2;
                $mail->Subject = "Demande de prix - Réf: " . $besoin_id;

                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

                // Construction et envoi à chaque fournisseur
                foreach ($fournisseurs_data as $fdata) {
                    $f_info = $pdo->prepare("SELECT email, nom FROM fournisseurs WHERE id = ?");
                    $f_info->execute([$fdata['id']]);
                    $fournisseur = $f_info->fetch();

                    if ($fournisseur && !empty($fournisseur['email'])) {
                        
                        $lien_fournisseur = $base_url . "/soumettre_offre.php?token=" . $fdata['token'];

                        $message_html = "<p>Bonjour <strong>" . htmlspecialchars($fournisseur['nom']) . "</strong>,</p>";
                        $message_html .= "<p>Veuillez trouver ci-joint une demande de prix relative à <strong>" . htmlspecialchars($titre_besoin) . "</strong> dont la livraison sera faite à <strong>nos locaux</strong>.</p>";
                        
                        $arts = $pdo->prepare("SELECT designation, unite, quantite FROM besoin_articles WHERE besoin_id = ?");
                        $arts->execute([$besoin_id]);
                        $articles = $arts->fetchAll();
                        
                        if (!empty($articles)) {
                            $message_html .= "<br><table border='1' cellspacing='0' cellpadding='5' style='border-collapse: collapse; width: 100%; max-width: 600px;'>
                                                <tr style='background-color: #f2f2f2;'><th>Désignation de l'Article</th><th>Quantité</th></tr>";
                            foreach ($articles as $a) {
                                $message_html .= "<tr><td>{$a['designation']}</td><td style='text-align: center;'>{$a['quantite']} {$a['unite']}</td></tr>";
                            }
                            $message_html .= "</table><br>";
                        }
                        
                        if (!empty($infos_besoin['description'])) {
                            $message_html .= "<div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #0d6efd;'>
                                                <strong>Détails techniques :</strong><br>" . nl2br(htmlspecialchars($infos_besoin['description'])) . "
                                              </div><br>";
                        }

                        $message_html .= "<p style='color: #d9534f; font-weight: bold;'>Date limite de réponse souhaitée : " . date('d/m/Y', strtotime($delai_reponse)) . "</p>";
                        
                        $message_html .= "<div style='text-align: center; margin: 30px 0;'>
                                            <a href='{$lien_fournisseur}' style='background-color: #198754; color: white; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Soumettre mon offre en ligne</a>
                                          </div>";

                        $message_html .= "<p>Nous restons à votre disposition pour toute demande de clarification.</p>";
                        $message_html .= "<p>Bien cordialement,<br><strong>L'équipe Logistique</strong></p>";

                        $mail->isHTML(true);
                        $mail->Body = $message_html;
                        
                        $mail->clearAttachments();
                        if ($fichier_nom) {
                            $mail->addAttachment(__DIR__ . '/uploads/' . $fichier_nom);
                        }

                        $mail->clearAddresses();
                        $mail->addAddress($fournisseur['email'], $fournisseur['nom']);
                        $mail->send();
                        $emails_envoyes++;
                    }
                }
                $_SESSION['success'] = "La demande a été lancée et $emails_envoyes e-mail(s) ont bien été envoyés.";

            } catch (Exception $e) {
                // L'e-mail a échoué (normal en local), MAIS la base de données est déjà sauvegardée !
                $_SESSION['warning'] = "Le dossier a bien été créé, mais l'envoi des e-mails a échoué (Erreur réseau locale). Vous pourrez contacter les fournisseurs manuellement.";
            }

        } catch (PDOException $e) {
            // Si la base de données crash, on annule
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Erreur SQL : " . $e->getMessage();
        }
    }
    header('Location: demande_proforma.php');
    exit();
}

// --- LOGIQUE DE FILTRE ---
$filter_status = $_GET['statut'] ?? 'actifs'; 
$search_query = $_GET['recherche'] ?? '';

function get_demandes_proforma($pdo, $status, $search) {
    try {
        $sql = "SELECT * FROM demandes_proforma";
        $conditions = [];
        $params = [];

        if ($status === 'actifs') {
            $conditions[] = "statut IN ('En attente', 'Réponses en cours')";
        } elseif ($status !== 'tous' && !empty($status)) {
            $conditions[] = "statut = ?";
            $params[] = $status;
        }
        
        if (!empty($search)) {
            $conditions[] = "titre_besoin LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY date_emission DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();

    } catch (PDOException $e) {
        return [];
    }
}

function get_demande_status_badge($statut) {
    $map = ['En attente' => 'bg-secondary', 'Réponses en cours' => 'bg-warning text-dark', 'Validé' => 'bg-success'];
    $class = $map[$statut] ?? 'bg-light text-dark';
    return '<span class="badge ' . $class . '">' . htmlspecialchars($statut) . '</span>';
}

$demandes_proforma = get_demandes_proforma($pdo, $filter_status, $search_query);

$besoins_disponibles = $pdo->query("SELECT id, titre FROM besoins WHERE statut = 'Validé' AND type_demande = 'Standard'")->fetchAll();

$all_status = $pdo->query("SELECT DISTINCT statut FROM demandes_proforma WHERE statut IS NOT NULL ORDER BY statut")->fetchAll(PDO::FETCH_COLUMN);
$fournisseurs_liste = $pdo->query("SELECT * FROM fournisseurs ORDER BY nom ASC")->fetchAll();
$pre_selected_besoin = $_GET['besoin_id'] ?? '';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes Proforma - Logisticien</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex vh-100">
        <?php include 'header.php'; ?>

        <div class="flex-fill d-flex flex-column main-content">
            <header class="bg-white border-bottom px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Tableau de bord Proformas</h2>
                        <p class="text-muted mb-0 small">Suivi et renseignement des offres reçues</p>
                    </div>
                    <a href="besoins_logisticien.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Retour aux besoins</a>
                </div>
            </header>
            
            <main class="flex-fill overflow-auto p-4">
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-check-circle-fill me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $_SESSION['warning']; unset($_SESSION['warning']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert"><i class="bi bi-x-circle-fill me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                
                <div class="d-flex justify-content-end mb-4">
                    <button class="btn btn-primary d-flex align-items-center shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#newProformaModal" id="btnOpenModal">
                        <i class="bi bi-envelope-paper me-2"></i>Diffuser une nouvelle demande
                    </button>
                </div>
                
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-body bg-white rounded">
                        <form method="GET" action="demande_proforma.php" class="row g-3 align-items-center">
                            <div class="col-md-5">
                                <input type="text" name="recherche" class="form-control" placeholder="Rechercher un dossier..." value="<?= htmlspecialchars($search_query) ?>">
                            </div>
                            <div class="col-md-4">
                                <select name="statut" class="form-select" onchange="this.form.submit()">
                                    <option value="actifs" <?= ($filter_status === 'actifs') ? 'selected' : '' ?>>🔥 À renseigner (En cours)</option>
                                    <option value="tous" <?= ($filter_status === 'tous') ? 'selected' : '' ?>>📁 Afficher tout l'historique</option>
                                    <option disabled>──────────</option>
                                    <?php foreach ($all_status as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= ($filter_status === $status) ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Filtrer</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white pb-0 border-0">
                        <h5 class="card-title fw-bold text-primary mb-2">Dossiers de Proformas</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th>Réf Proforma</th>
                                        <th>Besoin Lié</th>
                                        <th>Date d'envoi</th> 
                                        <th>Date Limite</th>
                                        <th>Statut</th>
                                        <th class="text-end">Renseigner / Dépouiller</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($demandes_proforma)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Aucune demande de proforma dans cette catégorie.</td></tr>
                                    <?php else: foreach ($demandes_proforma as $demande): ?>
                                        <tr>
                                            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= htmlspecialchars($demande['id']) ?></code></td>
                                            <td>
                                                <a href="view_besoin.php?id=<?= urlencode($demande['besoin_id']) ?>" class="text-decoration-none fw-bold">
                                                    <?= htmlspecialchars($demande['titre_besoin']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($demande['date_emission'])) ?></td>
                                            <td><span class="text-danger fw-bold"><?= date('d/m/Y', strtotime($demande['delai_reponse'])) ?></span></td>
                                            <td><?= get_demande_status_badge($demande['statut']) ?></td>
                                            <td class="text-end">
                                                <a href="gerer_reponses.php?id=<?= htmlspecialchars($demande['id']) ?>" class="btn btn-primary btn-sm px-3 shadow-sm">
                                                    <i class="bi bi-journal-plus me-1"></i> Saisir Offres
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <div class="modal fade" id="newProformaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-primary">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-envelope-paper me-2"></i>Diffuser une nouvelle demande</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="demande_proforma.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="proformaBesoinId" class="form-label fw-bold">Besoin à satisfaire (Type Proforma) <span class="text-danger">*</span></label>
                                <select class="form-select border-primary" id="proformaBesoinId" name="proformaBesoinId" required>
                                    <option value="" disabled <?= empty($pre_selected_besoin) ? 'selected' : '' ?>>-- Sélectionnez un besoin --</option>
                                    <?php foreach ($besoins_disponibles as $b): ?>
                                        <option value="<?= htmlspecialchars($b['id']) ?>" <?= ($pre_selected_besoin === $b['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['titre']) ?> (<?= htmlspecialchars($b['id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="proformaDelai" class="form-label fw-bold">Date limite de réception <span class="text-danger">*</span></label>
                                <input type="date" class="form-control border-danger" id="proformaDelai" name="proformaDelai" required min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <div class="mb-4 bg-light p-3 rounded">
                            <label for="proformaFichier" class="form-label fw-bold"><i class="bi bi-paperclip me-1"></i>Joindre un TDR / Cahier des charges (Optionnel)</label>
                            <input class="form-control" type="file" id="proformaFichier" name="proformaFichier" accept=".pdf,.doc,.docx">
                            <small class="text-muted">S'il est présent, il sera envoyé aux fournisseurs en plus du tableau des articles.</small>
                        </div>

                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-people-fill text-primary me-2"></i>Sélectionnez les fournisseurs à consulter :</h6>
                        <?php if(empty($fournisseurs_liste)): ?>
                            <div class="alert alert-warning small">Aucun fournisseur enregistré. Allez dans le menu Fournisseurs pour en ajouter.</div>
                        <?php else: ?>
                            <div style="max-height: 250px; overflow-y: auto;" class="border rounded p-3 bg-white shadow-sm">
                                <?php foreach($fournisseurs_liste as $f): ?>
                                    <div class="form-check mb-2 pb-2 border-bottom">
                                        <input class="form-check-input" type="checkbox" name="fournisseurs[]" value="<?= $f['id'] ?>" id="f_<?= $f['id'] ?>">
                                        <label class="form-check-label w-100" style="cursor:pointer;" for="f_<?= $f['id'] ?>">
                                            <strong class="text-dark"><?= htmlspecialchars($f['nom']) ?></strong> 
                                            <br>
                                            <small class="text-muted">
                                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($f['domaine']) ?></span> 
                                                <?= htmlspecialchars($f['email']) ?>
                                            </small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="submit_proforma" class="btn btn-primary px-4 fw-bold" onclick="return confirm('Confirmer la création ?')">
                            <i class="bi bi-send-fill me-2"></i> Lancer la consultation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            if(urlParams.has('besoin_id')) {
                document.getElementById('btnOpenModal').click();
            }
        });
    </script>
</body>
</html>     