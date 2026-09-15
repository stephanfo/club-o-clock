// ── Liste Semaine du planning mobile : où elle s'ouvre (#69) ──
//
// La liste défile DANS `.plan-scroll-m`, pas dans le document. Trois cas, décidés au premier rendu :
//
// 1. Retour arrière vers le planning : on rend la position quittée. Livewire ne restaure le défilement
//    d'un élément que s'il porte `wire:scroll` — inutilisable en 3.8 : l'attribut passe aussi par le
//    gestionnaire générique `wire:<événement>`, qui lie un écouteur `scroll` évaluant `$wire.` tout
//    court, d'où une SyntaxError à chaque défilement. On mémorise donc nous-mêmes la position dans un
//    attribut, posé sur `alpine:navigating` : Livewire photographie le HTML juste APRÈS cet événement,
//    l'attribut voyage donc dans l'instantané que le retour arrière réaffiche. Une page servie par le
//    serveur (arrivée, rechargement, retour hors cache) ne le porte jamais.
// 2. Arrivée : on aligne le haut du groupe `data-arrivee` (jour courant ou prochain jour peuplé, choisi
//    par Planning::arrivalDay()) sur le haut du conteneur ; son en-tête collant, premier enfant, se
//    loge ainsi exactement. Sans animation : l'écran s'ouvre déjà au bon endroit.
// 3. Ensuite, plus aucun saut automatique : `init` ne rejoue pas sur un morphing Livewire (filtre,
//    inscription depuis une carte). Seul un changement de semaine ou de vue remet la liste en haut —
//    sans quoi le morphing gardait le défilement de la semaine quittée.
//
// Sur desktop le bloc est masqué : rectangles et défilement valent 0, rien ne bouge.

function planningSemaine() {
    return {
        init() {
            const retour = this.$el.dataset.retourY;
            if (retour !== undefined) {
                delete this.$el.dataset.retourY;
                this.$el.scrollTop = Number(retour);
            } else {
                const jour = this.$el.querySelector('[data-arrivee]');
                if (jour) {
                    this.$el.scrollTop += jour.getBoundingClientRect().top - this.$el.getBoundingClientRect().top;
                }
            }

            const enHaut = () => { this.$el.scrollTop = 0; };
            this.$watch('$wire.anchor', enHaut);
            this.$watch('$wire.view', enHaut);
        },

        memoriser() {
            this.$el.dataset.retourY = this.$el.scrollTop;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('planningSemaine', planningSemaine);
});
