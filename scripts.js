// Scripts pour l'application Gestion Marchés

// Configuration des données
const marchesData = {
    'M2024-007': {
        titre: 'Équipements informatiques',
        statut: 'nouveau_besoin'
    },
    'M2024-006': {
        titre: 'Services de sécurité', 
        statut: 'attente_proforma'
    },
    'M2024-005': {
        titre: 'Formation personnel',
        statut: 'proforma_recu'
    },
    'M2024-004': {
        titre: 'Travaux de rénovation',
        statut: 'pv_depouillement' 
    },
    'M2024-003': {
        titre: 'Matériel de bureau',
        statut: 'bon_commande'
    }
};

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    initializeModals();
    initializeFormHandlers();
    setMinDate();
});

// Initialisation des graphiques
function initializeCharts() {
    if (typeof Chart === 'undefined') return;

    // Graphique mensuel
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Montant (F CFA)',
                    data: [850000, 720000, 950000, 1200000, 980000, 1100000],
                    backgroundColor: '#0d6efd',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return (value / 1000000).toFixed(1) + 'M F CFA';
                            }
                        }
                    }
                }
            }
        });
    }

    // Graphique de statut
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['En cours', 'Finalisés', 'En attente', 'Annulés'],
                datasets: [{
                    data: [35, 28, 15, 8],
                    backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
}

// Initialisation des modales
function initializeModals() {
    // Modal proforma
    const proformaModal = document.getElementById('proformaModal');
    if (proformaModal) {
        proformaModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const marcheId = button.getAttribute('data-marche');
            const titre = button.getAttribute('data-titre');
            
            document.getElementById('modalId').textContent = marcheId;
            document.getElementById('modalTitre').textContent = titre;
        });
    }

    // Modal upload
    const uploadModal = document.getElementById('uploadModal');
    if (uploadModal) {
        uploadModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const type = button.getAttribute('data-type');
            const marche = button.getAttribute('data-marche');
            
            const title = type === 'livraison' ? 'Téléverser bon de livraison' : 'Téléverser facture';
            document.getElementById('uploadModalTitle').textContent = title;
        });
    }
}

// Gestionnaires de formulaires
function initializeFormHandlers() {
    // Formulaire nouveau besoin
    const nouveauBesoinForm = document.getElementById('nouveauBesoinForm');
    if (nouveauBesoinForm) {
        nouveauBesoinForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleNouveauBesoinSubmit();
        });
    }
}

// Définir la date minimum à aujourd'hui
function setMinDate() {
    const today = new Date().toISOString().split('T')[0];
    const delaiInput = document.getElementById('delaiProforma');
    if (delaiInput) {
        delaiInput.min = today;
    }
}

// Gestion de la soumission du nouveau besoin
function handleNouveauBesoinSubmit() {
    // Récupération des données du formulaire
    const formData = new FormData(document.getElementById('nouveauBesoinForm'));
    
    // Validation basique
    const titre = formData.get('titre');
    const direction = formData.get('direction');
    const description = formData.get('description');
    const responsable = formData.get('responsable');
    const email = formData.get('email');
    const montantEstime = formData.get('montantEstime');
    const dateBesoins = formData.get('dateBesoins');
    
    if (!titre || !direction || !description || !responsable || !email || !montantEstime || !dateBesoins) {
        showAlert('Veuillez remplir tous les champs obligatoires.', 'danger');
        return;
    }

    // Simulation de la soumission
    const nouveauId = 'M2024-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
    
    // Affichage du modal de succès
    document.getElementById('marcheId').textContent = nouveauId;
    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
    successModal.show();
    
    // Réinitialisation du formulaire
    document.getElementById('nouveauBesoinForm').reset();
    
    // Simulation d'envoi de notification
    setTimeout(() => {
        showToast('Notification envoyée au logisticien', 'success');
    }, 1000);
}

// Activer le délai proforma
function activerDelai() {
    const delai = document.getElementById('delaiProforma').value;
    if (!delai) {
        showAlert('Veuillez sélectionner une date limite.', 'warning');
        return;
    }

    // Fermer le modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('proformaModal'));
    modal.hide();
    
    // Afficher le toast de succès
    showToast('Demande proforma envoyée et délai activé', 'success');
    
    // Mettre à jour l'interface (simulation)
    setTimeout(() => {
        location.reload();
    }, 1500);
}

// Créer PV de dépouillement
function creerPV(marcheId) {
    showToast(`PV de dépouillement initié pour ${marcheId}`, 'success');
    
    // Simulation de changement de statut
    setTimeout(() => {
        // Ici on pourrait mettre à jour l'interface
        console.log(`Marché ${marcheId} - Statut changé vers PV dépouillement`);
    }, 1000);
}

// Créer bon de commande
function creerBonCommande(marcheId) {
    showToast(`Bon de commande créé pour ${marcheId}`, 'success');
    
    // Simulation de changement de statut
    setTimeout(() => {
        console.log(`Marché ${marcheId} - Statut changé vers bon de commande`);
    }, 1000);
}

// Upload de document
function uploadDocument() {
    const fileInput = document.getElementById('uploadFile');
    const commentaire = document.getElementById('commentaire').value;
    
    if (!fileInput.files.length) {
        showAlert('Veuillez sélectionner un fichier.', 'warning');
        return;
    }
    
    // Simulation de l'upload
    const modal = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
    modal.hide();
    
    showToast('Document téléversé avec succès', 'success');
    
    // Réinitialiser le formulaire
    fileInput.value = '';
    document.getElementById('commentaire').value = '';
}

// Changer de rôle (demo)
function switchRole(role) {
    const roleLabels = {
        'chef_projet': 'Chef de Projet',
        'logisticien': 'Logisticien',
        'charge_passation': 'Chargée de Passation'
    };
    
    showToast(`Rôle changé vers: ${roleLabels[role]}`, 'info');
    
    // Redirection selon le rôle
    setTimeout(() => {
        switch(role) {
            case 'chef_projet':
                window.location.href = 'nouveau-besoin.html';
                break;
            case 'logisticien':
                window.location.href = 'workflow.html';
                break;
            case 'charge_passation':
                window.location.href = 'index.html';
                break;
        }
    }, 1000);
}

// Utilitaires
function showToast(message, type = 'success') {
    const toastElement = document.getElementById('successToast');
    const toastMessage = document.getElementById('toastMessage');
    
    if (toastElement && toastMessage) {
        toastMessage.textContent = message;
        
        // Changer la couleur selon le type
        const toastHeader = toastElement.querySelector('.toast-header');
        const icon = toastHeader.querySelector('i');
        
        // Réinitialiser les classes
        icon.className = 'me-2';
        
        switch(type) {
            case 'success':
                icon.classList.add('bi', 'bi-check-circle-fill', 'text-success');
                break;
            case 'warning':
                icon.classList.add('bi', 'bi-exclamation-triangle-fill', 'text-warning');
                break;
            case 'danger':
                icon.classList.add('bi', 'bi-x-circle-fill', 'text-danger');
                break;
            case 'info':
                icon.classList.add('bi', 'bi-info-circle-fill', 'text-info');
                break;
        }
        
        const toast = new bootstrap.Toast(toastElement);
        toast.show();
    }
}

function showAlert(message, type = 'info') {
    // Créer une alerte Bootstrap dynamique
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.setAttribute('role', 'alert');
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insérer au début du main
    const main = document.querySelector('main');
    if (main) {
        main.insertBefore(alertDiv, main.firstChild);
        
        // Auto-remove après 5 secondes
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

// Gestion du drag & drop pour les fichiers
function initializeDragDrop() {
    const dropZone = document.querySelector('.upload-area');
    if (!dropZone) return;
    
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
    
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
    });
    
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const fileInput = document.getElementById('documents');
            if (fileInput) {
                fileInput.files = files;
                showToast(`${files.length} fichier(s) sélectionné(s)`, 'info');
            }
        }
    });
}

// Initialiser le drag & drop au chargement
document.addEventListener('DOMContentLoaded', initializeDragDrop);