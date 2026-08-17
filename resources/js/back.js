// ── Retour contextuel de la topbar (correctif navigation 2026-08-02) ──
//
// Le chevron « retour » des écrans plein-format pointait vers une URL EN DUR (« retour aux
// parcours », « retour au planning »…). Conséquence : revenir d'une fiche parcours atterrissait
// toujours sur la bibliothèque — filtres perdus — même quand on venait d'une séance.
//
// Le vrai retour arrière est `history.back()` : il restaure la page précédente telle qu'elle était
// (query string des filtres, scroll, snapshot wire:navigate), là où une URL reconstruite ne peut au
// mieux que l'approcher. Mais il n'est utilisable que s'il RESTE dans l'app : sur une fiche ouverte
// depuis un lien externe ou un nouvel onglet, il ferait sortir du site. D'où le repli sur l'URL en
// dur, qui reste le `href` du lien — donc fonctionnel sans JS et ouvrable en nouvel onglet.
//
// ─ Où stocker la profondeur, et pourquoi PAS dans history.state ─
// Premier jet (corrigé le 2026-08-02) : marquer l'entrée via `history.replaceState`. Inopérant —
// le `push()` de Livewire **reconstruit** l'objet state (`state = { alpine: {...} }`) au lieu de
// l'étendre, donc toute clé maison est détruite à chaque navigation SPA. Le marqueur était absent
// partout et le retour retombait systématiquement sur le href, sur TOUS les écrans atteints en SPA.
//
// D'où un simple compteur en `sessionStorage`, que Livewire ne touche pas : +1 par navigation avant,
// −1 par retour arrière. `history.length` ne conviendrait pas — il compte l'onglet entier, pages
// visitées avant d'arriver sur le site comprises.

const KEY = 'cm:navDepth';

function depth() {
    try {
        return Number(sessionStorage.getItem(KEY) || 0);
    } catch (e) {
        return 0;   // Safari en navigation privée peut refuser sessionStorage → repli sur le href
    }
}

function setDepth(value) {
    try {
        sessionStorage.setItem(KEY, String(Math.max(1, value)));
    } catch (e) { /* cf. depth() */ }
}

// Un retour arrière émet popstate PUIS livewire:navigated (rendu du snapshot). Ce drapeau évite que
// le second annule le premier : sans lui, reculer puis avancer laisserait le compteur figé.
let goingBack = false;

window.addEventListener('popstate', () => {
    goingBack = true;
    setDepth(depth() - 1);
});

// Un rechargement (F5) réémet `livewire:navigated` sans qu'aucune page ne s'ajoute à la pile : il
// ne doit pas incrémenter, sinon le compteur dérive à chaque F5 et le chevron croit avoir un
// historique inexistant. `PerformanceNavigationTiming.type` distingue reload de navigate.
const isReload = performance.getEntriesByType('navigation')[0]?.type === 'reload';
let firstRender = true;

document.addEventListener('livewire:navigated', () => {
    // Émis aussi au premier rendu d'une page chargée classiquement (le `setTimeout` de
    // fireEventForOtherLibrariesToHookInto), pas seulement sur une navigation SPA : un seul
    // écouteur couvre les deux cas.
    const wasFirst = firstRender;
    firstRender = false;

    if (goingBack) {
        goingBack = false;
        return;
    }
    if (wasFirst && isReload) return;   // F5 : la page est déjà comptée
    setDepth(depth() + 1);
});

/**
 * Retour du chevron de topbar. Renvoie `true` si le retour historique a été consommé, `false` pour
 * laisser le navigateur suivre le `href` de repli (page d'entrée, ou stockage indisponible).
 */
window.clubBack = function clubBack() {
    if (depth() <= 1) return false;   // 1 = page d'entrée dans l'app : rien derrière elle
    history.back();
    return true;
};
