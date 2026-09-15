// ── Barre de navigation basse décollée sur iOS PWA (#42) ──
//
// Symptôme, en PWA installée (standalone) sur iPhone : la `.botnav` (position: fixed; bottom: 0) ne
// colle plus au bas de l'écran. Deux déclencheurs observés :
//   - plusieurs reprises de l'app sans la tuer (le document n'est pas rechargé, iOS restaure la
//     fenêtre avec une mesure de viewport faite dans un état antérieur) ;
//   - passer en paysage, naviguer (écran Alertes), revenir en portrait : la barre flotte environ une
//     fois sa hauteur trop haut.
// Tuer l'app corrige, parce qu'un chargement neuf refait la mesure. Seuls les écrans dont c'est le
// DOCUMENT qui défile sont touchés ; le Planning, borné à 100dvh avec défilement interne, ne l'est pas.
//
// Ce n'est pas une valeur CSS fausse (la hauteur de la barre est forcée, cf. app.css) mais un
// layout viewport que WebKit ne recalcule pas après rotation ou reprise. Aucun événement ne dit
// « le viewport est périmé » : on force donc un recalcul aux moments où il peut l'être.
//
// ─ Pourquoi pas l'alignement de tous les écrans sur le modèle du Planning ─
// Il soustrairait la barre au défilement du document, mais au prix de la restauration du défilement
// par wire:navigate, du retour en haut par la barre d'état iOS, et d'une revue de chaque écran. Pas
// sans mesure préalable — d'où le panneau de diagnostic ci-dessous.

const standalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

// Réécrire le contenu de la meta viewport (même valeur) oblige WebKit à recalculer le layout
// viewport ; le reflow de la barre et le scrollTo sur place repeignent les éléments fixes contre la
// nouvelle mesure. Réservé aux moments sûrs : jamais sur un simple resize, qui accompagne aussi le
// clavier et ferait sauter un champ en cours de saisie.
function recalerViewport() {
    const meta = document.querySelector('meta[name="viewport"]');
    if (meta) {
        const contenu = meta.getAttribute('content');
        meta.setAttribute('content', contenu + ' ');
        meta.setAttribute('content', contenu);
    }
    recalerBarre();
}

function recalerBarre() {
    const barre = document.querySelector('.botnav');
    if (barre) {
        barre.style.display = 'none';
        void barre.offsetHeight;
        barre.style.display = '';
    }
    window.scrollTo(window.scrollX, window.scrollY);
    diagnostic?.maj();
}

// Deux images plus tard : iOS livre l'orientationchange avant d'avoir appliqué les nouvelles
// dimensions, un recalage immédiat mesurerait encore l'ancien état.
const apresRepeinte = (fn) => requestAnimationFrame(() => requestAnimationFrame(fn));

if (standalone()) {
    window.matchMedia('(orientation: portrait)').addEventListener('change', () => apresRepeinte(recalerViewport));
    window.addEventListener('pageshow', () => apresRepeinte(recalerViewport));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') apresRepeinte(recalerViewport);
    });
    // Une navigation SPA remplace le contenu sous une barre qui, elle, reste en place : s'il y a eu
    // rotation pendant l'écran précédent, c'est ici que l'écart se voit.
    document.addEventListener('livewire:navigated', () => apresRepeinte(recalerBarre));
}

// ── Panneau de diagnostic (#42) ──
// Le défaut ne se reproduit ni sur desktop ni dans Playwright : il faut les mesures de l'appareil au
// moment où il se produit. Sans effet pour qui ne l'active pas. Deux interrupteurs, mémorisés :
//   - `?diag=viewport` / `?diag=off` dans l'adresse, pour le navigateur ;
//   - cinq tapes rapides sur le titre de la topbar, pour la PWA installée : elle n'a pas de barre
//     d'adresse, et iOS lui donne un stockage SÉPARÉ de celui de Safari — une activation faite
//     dans Safari avant l'ajout à l'écran d'accueil ne la suit pas.
const CLE = 'club.diag.viewport';
let diagnostic = null;
let diagActif = false;

function lireDrapeau() {
    try {
        const valeur = new URLSearchParams(window.location.search).get('diag');
        if (valeur === 'viewport') localStorage.setItem(CLE, '1');
        if (valeur === 'off') localStorage.removeItem(CLE);
        return localStorage.getItem(CLE) === '1';
    } catch {
        return false;
    }
}

function creerDiagnostic() {
    const panneau = document.createElement('div');
    panneau.setAttribute('aria-hidden', 'true');
    panneau.style.cssText = [
        'position:fixed', 'left:var(--space-2)', 'top:calc(env(safe-area-inset-top) + 64px)', 'z-index:1300',
        'pointer-events:none', 'white-space:pre', 'font:var(--text-xs)/1.35 var(--font-mono)',
        'color:var(--fg-on-dark)', 'background:var(--ink)', 'opacity:.85',
        'padding:var(--space-1) var(--space-2)', 'border-radius:var(--radius-sm)',
    ].join(';');
    // Sonde : lire env(safe-area-inset-bottom) tel que le moteur le résout à cet instant.
    const sonde = document.createElement('div');
    sonde.style.cssText = 'position:fixed;visibility:hidden;height:env(safe-area-inset-bottom);width:0';

    let evenement = 'chargement';
    const maj = () => {
        if (!diagActif) {
            panneau.remove();
            sonde.remove();
            return;
        }
        if (!panneau.isConnected) document.body.append(panneau, sonde);
        const vv = window.visualViewport;
        const barre = document.querySelector('.botnav')?.getBoundingClientRect();
        const r = (n) => (n === undefined ? '–' : Math.round(n * 10) / 10);
        panneau.textContent = [
            `évt       ${evenement}`,
            `mode      ${standalone() ? 'standalone' : 'navigateur'} ${screen.orientation?.type ?? ''}`,
            `inner     ${window.innerWidth}×${window.innerHeight}`,
            `client    ${document.documentElement.clientWidth}×${document.documentElement.clientHeight}`,
            `vv        ${r(vv?.width)}×${r(vv?.height)} top ${r(vv?.offsetTop)} ×${r(vv?.scale)}`,
            `screen    ${screen.width}×${screen.height}`,
            `safe-bas  ${sonde.getBoundingClientRect().height}`,
            `scrollY   ${r(window.scrollY)}`,
            `botnav    top ${r(barre?.top)} bas ${r(barre?.bottom)} h ${r(barre?.height)}`,
            `écart     ${barre ? r(window.innerHeight - barre.bottom) : '–'}`,
        ].join('\n');
    };
    const sur = (cible, nom) => cible?.addEventListener(nom, () => { evenement = nom; maj(); }, { passive: true });
    sur(window, 'resize');
    sur(window, 'scroll');
    sur(window, 'pageshow');
    sur(window.visualViewport, 'resize');
    sur(window.visualViewport, 'scroll');
    sur(document, 'visibilitychange');
    sur(document, 'livewire:navigated');
    window.matchMedia('(orientation: portrait)').addEventListener('change', () => { evenement = 'orientation'; apresRepeinte(maj); });
    return { maj };
}

function basculerDiagnostic(actif) {
    diagActif = actif;
    try {
        if (actif) localStorage.setItem(CLE, '1');
        else localStorage.removeItem(CLE);
    } catch {
        // Stockage indisponible : le panneau vaut pour la page en cours seulement.
    }
    diagnostic ??= creerDiagnostic();
    diagnostic.maj();
}

// Délégation sur document : wire:navigate remplace le contenu du body, titre compris. `pointerup`
// et non `click` : iOS n'émet pas toujours de click remontant depuis un élément non cliquable.
let tapes = [];
document.addEventListener('pointerup', (e) => {
    if (!e.target.closest?.('.topbar-title')) return;
    const maintenant = Date.now();
    tapes = [...tapes.filter((t) => maintenant - t < 2000), maintenant];
    if (tapes.length >= 5) {
        tapes = [];
        basculerDiagnostic(!diagActif);
    }
});

if (lireDrapeau()) {
    const demarrer = () => basculerDiagnostic(true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrer);
    else demarrer();
}
