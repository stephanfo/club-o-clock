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
function chargerServiceWorker(clients, { pushManager = {}, fetch = async () => ({}) } = {}) {
    const ecouteurs = {};
    const ouvertes = [];

    const self = {
        location: { origin: 'https://club.test' },
        addEventListener: (type, handler) => {
            ecouteurs[type] = handler;
        },
        registration: { showNotification: async () => {}, pushManager },
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
        fetch,
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

test('même fiche mais autre onglet demandé : la fenêtre y navigue (#132)', async () => {
    // Le défaut : seul le chemin était comparé, donc une fenêtre déjà sur la fiche était juste
    // focalisée — l'onglet restait Infos et le débrief ou le « j'aime » annoncé n'apparaissait pas.
    const seance = fenetre('https://club.test/seances/12');

    await cliquer('/seances/12?tab=debriefs', [seance]);

    assert.equal(seance.navigatedTo, 'https://club.test/seances/12?tab=debriefs');
    assert.equal(seance.focused, true);
});

test('changement d’onglet : c’est la fenêtre sur la fiche qui navigue, pas une autre (#132)', async () => {
    const planning = fenetre('https://club.test/planning');
    const seance = fenetre('https://club.test/seances/12');

    await cliquer('/seances/12?tab=debriefs', [planning, seance]);

    assert.equal(seance.navigatedTo, 'https://club.test/seances/12?tab=debriefs');
    assert.equal(planning.navigatedTo, null, 'le planning ne doit pas être détourné');
});

test('fiche déjà ouverte sur l’onglet demandé : simple focus (#132)', async () => {
    // Contrôle positif apparié : la query compte, mais une fenêtre déjà sur la cible n'est ni
    // rechargée (un débrief en cours d'écriture y serait perdu) ni doublée.
    const seance = fenetre('https://club.test/seances/12?tab=debriefs&from=push#d3');

    const { ouvertes } = await cliquer('/seances/12?tab=debriefs', [seance]);

    assert.equal(seance.focused, true);
    assert.equal(seance.navigatedTo, null);
    assert.deepEqual(ouvertes, []);
});

test('navigate() refusé : on ouvre une fenêtre au lieu de ne rien faire (#132)', async () => {
    // Fenêtre non contrôlée (rechargement forcé, ancienne version du SW) : la spec rejette
    // navigate() en TypeError. Sans repli, la notification se fermait et le clic était mort.
    const planning = fenetre('https://club.test/planning');
    planning.navigate = async () => {
        throw new TypeError('client non contrôlé');
    };

    const { ouvertes } = await cliquer('/seances/12?tab=debriefs', [planning]);

    assert.deepEqual(ouvertes, ['https://club.test/seances/12?tab=debriefs']);
});

test('navigate() sans fenêtre en retour : on en ouvre une (#132)', async () => {
    const planning = fenetre('https://club.test/planning');
    planning.navigate = async () => null;

    const { ouvertes } = await cliquer('/seances/12', [planning]);

    assert.deepEqual(ouvertes, ['https://club.test/seances/12']);
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

// ── pushsubscriptionchange (#96) : le nouvel endpoint doit parvenir au serveur ──

/** Rejoue pushsubscriptionchange ; renvoie les appels fetch et les abonnements créés. */
async function renouveler(evenement, { jetonOk = true, existant = null } = {}) {
    const appels = [];
    const crees = [];
    const nouvel = (endpoint) => ({ endpoint, toJSON: () => ({ keys: { p256dh: 'P', auth: 'A' } }) });
    const { ecouteurs } = chargerServiceWorker([], {
        pushManager: {
            getSubscription: async () => existant,
            subscribe: async (options) => {
                crees.push(options);

                return nouvel('https://push.test/recree');
            },
        },
        fetch: async (url, init = {}) => {
            appels.push({ url, init });

            return url === '/push/jeton'
                ? { ok: jetonOk, json: async () => ({ token: 'JETON-FRAIS' }) }
                : { ok: true };
        },
    });

    let attendu;
    ecouteurs.pushsubscriptionchange({ ...evenement, waitUntil: (p) => { attendu = p; } });
    await attendu;

    return { appels, crees, nouvel };
}

test('le nouvel abonnement fourni par le navigateur est transmis avec un jeton frais', async () => {
    const { appels } = await renouveler({ newSubscription: { endpoint: 'https://push.test/neuf', toJSON: () => ({ keys: { p256dh: 'P', auth: 'A' } }) } });

    assert.equal(appels.length, 2);
    assert.equal(appels[0].url, '/push/jeton');
    assert.equal(appels[1].url, '/push/subscriptions');
    assert.equal(appels[1].init.method, 'POST');
    assert.equal(appels[1].init.headers['X-CSRF-TOKEN'], 'JETON-FRAIS');
    assert.equal(JSON.parse(appels[1].init.body).endpoint, 'https://push.test/neuf');
});

test('sans nouvel abonnement, on se réabonne avec la clé de l\'ancien', async () => {
    const cle = new Uint8Array([1, 2, 3]).buffer;
    const { appels, crees } = await renouveler({ oldSubscription: { options: { applicationServerKey: cle } } });

    assert.equal(crees.length, 1);
    assert.equal(crees[0].applicationServerKey, cle);
    assert.equal(crees[0].userVisibleOnly, true);
    assert.equal(JSON.parse(appels[1].init.body).endpoint, 'https://push.test/recree');
});

test('session perdue : rien n\'est posté, et l\'événement ne lève pas', async () => {
    const { appels } = await renouveler(
        { newSubscription: { endpoint: 'https://push.test/neuf', toJSON: () => ({ keys: {} }) } },
        { jetonOk: false },
    );

    assert.deepEqual(appels.map((a) => a.url), ['/push/jeton']);
});
