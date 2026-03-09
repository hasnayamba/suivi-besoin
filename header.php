<?php
// On récupère le nom du fichier de la page actuelle.
$currentPage = basename($_SERVER['SCRIPT_NAME']);

// Définir les pages associées à chaque lien principal pour l'état "active"
$besoinsPages = ['besoins_logisticien.php', 'view_besoin.php'];
$proformaPages = ['demande_proforma.php', 'gerer_reponses.php', 'modifier_demande.php'];
$aoPages = ['appel_offre.php', 'suivi_ao.php', 'depouillement_ao.php']; // NOUVEAU
$marchesPages = ['marches.php', 'gerer_marche.php'];
?>
<nav class="sidebar bg-white border-end" style="width: 260px;">
    <div class="p-4 border-bottom"><h5 class="mb-1">SWISSCONTACT</h5>
    <small class="opacity-75">Suivie Achat</small>
</div>
    <div class="p-3">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($currentPage == 'logisticien.php') ? 'active' : '' ?>" href="logisticien.php">
                    <i class="bi bi-house me-2"></i>Tableau de bord
                </a>
            </li>
        </ul>
        <div class="mt-4">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center <?= in_array($currentPage, $besoinsPages) ? 'active' : '' ?>" href="besoins_logisticien.php">
                        <i class="bi bi-clipboard-check me-2"></i>Besoins reçus
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center <?= in_array($currentPage, $proformaPages) ? 'active' : '' ?>" href="demande_proforma.php">
                        <i class="bi bi-box me-2"></i>Demandes proforma
                    </a>
                </li>
                <!-- NOUVEAU LIEN AJOUTÉ CI-DESSOUS -->
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center <?= in_array($currentPage, $aoPages) ? 'active' : '' ?>" href="suivi_ao.php">
                        <i class="bi bi-megaphone me-2"></i>Appels d'offres
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center <?= in_array($currentPage, $marchesPages) ? 'active' : '' ?>" href="marches.php">
                        <i class="bi bi-briefcase me-2"></i>Gérer les marchés
                    </a>
                </li>
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center text-primary" href="accueil_logisticien.php">
                        <i class="bi bi-arrow-left-circle me-2"></i>Retour au Portail
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>