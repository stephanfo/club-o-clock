// ── Modale calée sur la zone réellement visible, clavier compris (#98) ──
//
// Le scrim est `position:fixed; height:100dvh`. Or le clavier logiciel ne réduit pas `dvh` partout :
//   - iOS (iPhone, iPad) et Chrome Android récent : le clavier recouvre la page, seul le *visual
//     viewport* rétrécit — la modale passait dessous, pied et boutons compris ;
//   - certains Android (Samsung Internet, réglage « resizes-content ») : la page elle-même rétrécit,
//     `dvh` suit déjà.
// On ne devine pas la plateforme : on lit `window.visualViewport`, qui décrit la zone visible dans
// les deux cas, et on y cale le scrim (hauteur ET décalage — iOS fait défiler le layout viewport
// pour montrer le curseur). Là où le clavier ne change rien, la mesure vaut la fenêtre : sans effet.
//
// Usage : x-data="calageClavier" sur le `.scrim`. Sans visualViewport (navigateur ancien), rien ne
// change et le CSS (100dvh) reste la référence.
function calageClavier() {
    let caler = null;

    return {
        init() {
            const vv = window.visualViewport;
            if (!vv) return;

            caler = () => {
                this.$el.style.top = `${vv.offsetTop}px`;
                this.$el.style.height = `${vv.height}px`;
            };
            vv.addEventListener('resize', caler);
            vv.addEventListener('scroll', caler);
            caler();
        },

        destroy() {
            if (!caler) return;
            window.visualViewport.removeEventListener('resize', caler);
            window.visualViewport.removeEventListener('scroll', caler);
        },
    };
}

document.addEventListener('alpine:init', () => window.Alpine.data('calageClavier', calageClavier));
