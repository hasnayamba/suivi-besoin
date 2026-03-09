<?php
// On récupère le nom du fichier de la page actuelle
$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<nav class="sidebar bg-white border-end" style="width: 260px;">
    <div class="p-4 border-bottom">
        <h5 class="mb-1">Gestion des Contrats</h5>
    </div>
    <div class="p-3">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($currentPage == 'contrat_dashboard.php') ? 'active' : '' ?>" href="contrat_dashboard.php">
                    <i class="bi bi-grid-1x2 me-2"></i>Tableau de bord
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center <?= ($currentPage == 'ajouter_contrat.php') ? 'active' : '' ?>" href="ajouter_contrat.php">
                    <i class="bi bi-file-earmark-plus me-2"></i>Ajouter un contrat
                </a>
            </li>
        </ul>
        <div class="mt-4">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1">
                    <a class="nav-link d-flex align-items-center text-primary" href="accueil_logisticien.php">
                        <i class="bi bi-arrow-left-circle me-2"></i>Retour au Portail
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>