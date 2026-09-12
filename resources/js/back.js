// ── Retour contextuel de la topbar (correctif navigation 2026-08-02, revu 2026-09-12) ──
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
// ─ Où stocker l'historique de l'app, et pourquoi PAS dans history.state ─
// Premier jet (corrigé le 2026-08-02) : marquer l'entrée via `history.replaceState`. Inopérant —
// le `push()` de Livewire **reconstruit** l'objet state (`state = { alpine: {...} }`) au lieu de
// l'étendre, donc toute clé maison est détruite à chaque navigation SPA. Le marqueur était absent
// partout et le retour retombait systématiquement sur le href, sur TOUS les écrans atteints en SPA.
//
// D'où un stockage maison en `sessionStorage`, que Livewire ne touche pas. `history.length` ne
// conviendrait pas — il compte l'onglet entier, pages visitées avant d'arriver sur le site
// comprises.
//
// ─ Pourquoi une PILE D'URLS et plus un simple compteur (#26) ─
// `$this->redirect(route(...), navigate: true)` devient côté client `Alpine.navigate(url)` →
// `history.pushState` : l'écran quitté RESTE dans la pile. Après « éditer une séance → enregistrer »,
// l'entrée précédente est le formulaire ; un `history.back()` aveugle y retombait fidèlement, alors
// que le bouton annonçait « Planning ». Idem pour un parcours enregistré trois fois, qui empilait
// trois entrées identiques.
//
// La pile d'URLs permet un retour CIBLÉ : `clubBack(cible)` cherche la dernière occurrence de la
// cible sous l'entrée courante et fait `history.go(-n)` d'un coup. Cible absente de la pile → on
// retombe sur le `history.back()` d'avant, qui reste le bon geste quand on vient d'ailleurs que de
// la destination annoncée (une fiche parcours ouverte depuis une séance, par exemple).
//
// Un `go(-n)` peut viser une entrée sortie du cache de snapshots de Livewire (il est borné) : dans
// ce cas le plugin `navigate` refait simplement une requête réseau. Correct, seulement plus lent.
//
// ─ Pourquoi un rafraîchissement au retour (#25) ─
// Sur `popstate`, Livewire échange le HTML mémorisé et ne consulte JAMAIS le serveur
// (`whenTheBackOrForwardButtonIsClicked`). Ce HTML a été photographié au moment où l'on quittait la
// page : revenir au planning après s'être inscrit·e y réaffichait la carte d'avant l'inscription.
// On laisse l'instantané s'afficher (immédiat), puis on déclenche un `$refresh` — mais seulement
// si une action a eu lieu depuis.
//
// Le signal est le commit Livewire qui porte au moins un APPEL DE MÉTHODE (`calls`), par opposition
// à une simple synchronisation de propriété (`updates`, un `wire:model.live` de filtre). Les flashes
// ne pouvaient pas servir de marqueur : une inscription réussie n'en émet aucun — son retour visuel
// EST le changement d'état de la carte, et c'est précisément ce que le retour arrière ne montrait
// pas. La maille est volontairement large : un appel qui ne mutait rien coûte un `$refresh` au
// retour, jamais un affichage faux.

const KEY = 'cm:navStack';
const DIRTY = 'cm:navDirty';
/** Au-delà, on tronque : la pile ne sert qu'à retrouver une destination proche. */
const MAX = 30;

function lire() {
    try {
        const brut = JSON.parse(sessionStorage.getItem(KEY));

        return Array.isArray(brut) ? brut : [];
    } catch (e) {
        return [];   // Safari en navigation privée peut refuser sessionStorage → repli sur le href
    }
}

function ecrire(pile) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify(pile.slice(-MAX)));
    } catch (e) { /* cf. lire() */ }
}

/** Chemin seul : les filtres vivent dans la query string, deux vues du planning restent /planning. */
function chemin(url) {
    try {
        return new URL(url, location.origin).pathname;
    } catch (e) {
        return String(url);
    }
}

const ici = () => location.pathname;

function marquerMute() {
    try { sessionStorage.setItem(DIRTY, '1'); } catch (e) { /* cf. lire() */ }
}

/** Lit ET consomme le drapeau de mutation. */
function prendreMute() {
    try {
        const sale = sessionStorage.getItem(DIRTY) === '1';
        sessionStorage.removeItem(DIRTY);

        return sale;
    } catch (e) {
        return false;
    }
}

// Un retour arrière émet popstate PUIS livewire:navigated (rendu du snapshot). Ce drapeau évite que
// le second recompte le premier comme une navigation avant.
let goingBack = false;

// `history.go(-3)` n'émet qu'UN popstate, pas trois : on ne peut pas dépiler d'un cran. On resynchronise
// donc la pile sur l'URL où l'on vient d'atterrir — ce qui couvre aussi la marche avant du navigateur.
window.addEventListener('popstate', () => {
    goingBack = true;
    const pile = lire();
    const i = pile.lastIndexOf(ici());
    ecrire(i >= 0 ? pile.slice(0, i + 1) : [...pile, ici()]);
});

// Un rechargement (F5) réémet `livewire:navigated` sans qu'aucune page ne s'ajoute à la pile : il
// ne doit pas empiler, sinon la pile dérive à chaque F5 et le chevron croit avoir un historique
// inexistant. `PerformanceNavigationTiming.type` distingue reload de navigate.
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
        // L'instantané restauré est déjà à l'écran : on ne le remplace pas, on le corrige au vol.
        if (prendreMute()) {
            window.Livewire?.all().forEach((composant) => composant.$wire.$refresh());
        }

        return;
    }

    if (wasFirst && isReload) {
        // La page est déjà dans la pile (même onglet) ; on s'y resynchronise sans empiler.
        const pile = lire();
        if (pile[pile.length - 1] !== ici()) ecrire([...pile, ici()]);

        return;
    }

    ecrire([...lire(), ici()]);
});

// `livewire:init` peut avoir déjà été émis quand ce module s'exécute : on branche le hook dès que
// Livewire est là, sans attendre l'événement.
function brancherHook() {
    window.Livewire.hook('commit', ({ commit, succeed }) => {
        // `$refresh` est exclu : c'est CE module qui le déclenche au retour, il se remarquerait
        // lui-même et ferait payer un aller-retour au retour suivant.
        const agit = (commit.calls ?? []).some((appel) => appel.method !== '$refresh');
        if (agit) succeed(() => marquerMute());
    });
}

if (window.Livewire) {
    brancherHook();
} else {
    document.addEventListener('livewire:init', brancherHook);
}

/**
 * Retour du chevron de topbar. Renvoie `true` si le retour historique a été consommé, `false` pour
 * laisser le navigateur suivre le `href` de repli (page d'entrée, ou stockage indisponible).
 *
 * `cible` (optionnel) : l'URL que le bouton ANNONCE. Fournie, on remonte directement à sa dernière
 * occurrence dans la pile, en sautant les écrans transitoires laissés par une redirection après
 * enregistrement. Absente de la pile, ou non fournie, on fait le retour arrière ordinaire.
 */
window.clubBack = function clubBack(cible) {
    const pile = lire();
    if (pile.length <= 1) return false;   // 1 = page d'entrée dans l'app : rien derrière elle

    if (cible) {
        const voulu = chemin(cible);
        // On part de l'avant-dernière : l'entrée courante est le sommet, on ne « revient » pas dessus.
        for (let i = pile.length - 2; i >= 0; i--) {
            if (pile[i] === voulu) {
                history.go(i - (pile.length - 1));

                return true;
            }
        }
    }

    history.back();

    return true;
};
