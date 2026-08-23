// Tests du service worker (public/sw.js) — `node --test tests/E2E/sw.test.mjs`.
//
// Pourquoi ici et pas dans PHPUnit : `composer check` ne voit pas le JavaScript, et un service
// worker ne s'exécute ni dans PHP ni dans une page Playwright pilotable. Le runner intégré de Node
// suffit — aucune dépendance ajoutée, et la voie E2E utilise déjà Node.
//
// Le fichier est chargé dans une PORTÉE FEINTE (un faux `self`) qui capture les écouteurs posés au
// chargement, puis on rejoue `notificationclick` à la main. On teste le vrai fichier livré, pas une
// copie de sa logique.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const racine = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** Charge public/sw.js dans une portée feinte et renvoie ses écouteurs + la portée. */
function chargerServiceWorker(clients) {
    const ecouteurs = {};
    const ouvertes = [];

    const self = {
        location: { origin: 'https://club.test' },
        addEventListener: (type, handler) => {
            ecouteurs[type] = handler;
        },
        registration: { showNotification: async () => {} },
        skipWaiting: async () => {},
        clients: {
            matchAll: async () => clients,
            claim: async () => {},
            openWindow: async (url) => {
                ouvertes.push(url);

                return { url, focus: async () => {} };
            },
        },
    };

    const contexte = vm.createContext({
        self,
        URL,
        Promise,
        console,
        caches: { open: async () => ({ addAll: async () => {} }), keys: async () => [], delete: async () => {} },
        fetch: async () => ({}),
    });

    vm.runInContext(readFileSync(join(racine, 'public', 'sw.js'), 'utf8'), contexte);

    return { ecouteurs, ouvertes };
}

/** Fenêtre feinte : mémorise ce qu'on lui a fait subir (focus / navigate). */
function fenetre(url) {
    const f = {
        url,
        focused: false,
        navigatedTo: null,
        focus: async () => {
            f.focused = true;

            return f;
        },
        navigate: async (cible) => {
            f.navigatedTo = cible;

            return f;
        },
    };

    return f;
}

/** Rejoue un clic de notification vers `url` avec les fenêtres données. */
async function cliquer(url, fenetres) {
    const { ecouteurs, ouvertes } = chargerServiceWorker(fenetres);

    let attendu;
    await ecouteurs.notificationclick({
        notification: { close: () => {}, data: { url } },
        waitUntil: (p) => {
            attendu = p;
        },
    });
    await attendu;

    return { ouvertes };
}

test('la fenêtre déjà sur la cible est focalisée, jamais une autre', async () => {
    // Le défaut corrigé : la boucle tranchait fenêtre par fenêtre et détournait la PREMIÈRE au
    // chemin différent, sans avoir regardé si une autre était déjà sur la cible. Avec deux fenêtres
    // ouvertes, le planning — ou un formulaire à moitié rempli — partait ailleurs.
    const planning = fenetre('https://club.test/planning');
    const seance = fenetre('https://club.test/seances/12');

    await cliquer('/seances/12', [planning, seance]);

    assert.equal(seance.focused, true, 'la fenêtre déjà sur la séance doit être focalisée');
    assert.equal(planning.navigatedTo, null, 'le planning ne doit pas être détourné');
    assert.equal(planning.focused, false);
});

test('sans fenêtre sur la cible, une fenêtre existante y navigue', async () => {
    // Contrôle positif apparié : la réutilisation de fenêtre ne doit pas être perdue au passage —
    // c'est elle qui évite d'empiler un onglet de plus à chaque clic (launch_handler).
    const planning = fenetre('https://club.test/planning');

    await cliquer('/seances/12', [planning]);

    assert.equal(planning.navigatedTo, 'https://club.test/seances/12');
    assert.equal(planning.focused, true);
});

test('le chemin est comparé sans la query ni le hash', async () => {
    // Une égalité stricte d'URL ne matchait presque jamais (slash final, query, hash).
    const seance = fenetre('https://club.test/seances/12?from=push#detail');

    await cliquer('/seances/12', [seance]);

    assert.equal(seance.focused, true);
    assert.equal(seance.navigatedTo, null);
});

test('une fenêtre d’une autre origine est ignorée', async () => {
    const etranger = fenetre('https://autre.test/seances/12');

    const { ouvertes } = await cliquer('/seances/12', [etranger]);

    assert.equal(etranger.focused, false);
    assert.equal(etranger.navigatedTo, null);
    assert.deepEqual(ouvertes, ['https://club.test/seances/12']);
});

test('aucune fenêtre ouverte : on en ouvre une', async () => {
    const { ouvertes } = await cliquer('/seances/12', []);

    assert.deepEqual(ouvertes, ['https://club.test/seances/12']);
});
