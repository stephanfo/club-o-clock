// ── Modales : durée de vie strictement liée à la page (correctif 2026-08-28) ──
//
// Symptôme : après avoir validé « Oui, prévenir les inscrits » sur l'édition d'une séance, tout
// retour sur le formulaire rouvrait la modale — déjà validée, séance déjà enregistrée.
//
// Cause : `wire:navigate` mémorise le DOM COMPLET de la page quittée pour le rejouer au retour
// arrière (plugin navigate de Livewire : `updateCurrentPageHtmlInHistoryStateForLaterBackButtonClicks`).
// Or ce dialog redirige DEPUIS lui-même : il est encore à l'écran au moment de la photo, donc dans
// la photo. Aucune modale du projet ne touche à l'historique, rien ne la refermait au retour.
//
// L'événement `alpine:navigating` est émis JUSTE AVANT cette photo (cf. `navigateTo` : l'événement,
// puis la capture) : retirer le scrim ici suffit à ce que la page mémorisée soit propre. L'autre
// moitié est côté serveur — le composant remet son drapeau à zéro avant de rediriger
// (SessionForm::toShow), sans quoi l'état RÉHYDRATÉ rouvrirait la modale à la première action.
const fermerModales = () => document.querySelectorAll('.scrim').forEach((scrim) => scrim.remove());

document.addEventListener('alpine:navigating', fermerModales);

// Retour arrière hors SPA (lien externe, onglet restauré) : Safari ressort la page de son bfcache
// telle quelle, modale comprise. `persisted` distingue cette restauration d'un chargement normal.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) fermerModales();
});

// Échap ferme la modale du dessus. On ne réinvente pas la fermeture : le scrim porte déjà
// l'expression `close` du composant sous forme de `wire:click` (components/dialog.blade.php), on se
// contente de la déclencher. Une modale sans `close` — l'éditeur de débrief — n'a pas de wire:click
// sur son scrim : le clic y est sans effet, et c'est voulu (on ne ferme pas un éditeur sur une
// touche, le texte en cours serait perdu).
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    const scrims = document.querySelectorAll('.scrim');
    if (scrims.length === 0) return;

    scrims[scrims.length - 1].click();
});
