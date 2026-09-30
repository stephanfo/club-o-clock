// Tests du helper Web Push (resources/js/push.js) — `node --test tests/E2E/push.test.mjs`.
//
// Même principe que sw.test.mjs : le vrai fichier est chargé dans une portée feinte (window,
// navigator, Notification, localStorage et fetch simulés), puis on rejoue les gestes de l'onglet
// Notifs. Ce que Playwright ne sait pas faire : Chromium headless n'a pas de service push, donc
// pas d'abonnement réel à perdre ou à renouveler (#96).

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const racine = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readFileSync(join(racine, 'resources', 'js', 'push.js'), 'utf8');

// Clé VAPID publique du « serveur » (base64url) et sa forme binaire.
const CLE = 'AQID';
const CLE_OCTETS = [1, 2, 3];

function abonnement(endpoint, octets = CLE_OCTETS) {
    const sub = {
        endpoint,
        options: { applicationServerKey: new Uint8Array(octets).buffer },
        desabonne: false,
        toJSON: () => ({ keys: { p256dh: 'P', auth: 'A' } }),
        unsubscribe: async () => {
            sub.desabonne = true;

            return true;
        },
    };

    return sub;
}

/**
 * Charge push.js. `reponses` : file de réponses fetch (objets ou fonctions), consommée dans l'ordre ;
 * au-delà, 201. Renvoie clubPush, les appels fetch, l'état du faux navigateur et les balises meta.
 */
function charger({ sub = null, permission = 'granted', reponses = [], stockage = {}, utilisateur = '7' } = {}) {
    const appels = [];
    const metas = { 'csrf-token': 'JETON-VIEUX', 'vapid-public-key': CLE, 'club-user': utilisateur };
    const etat = { sub, crees: [] };

    const pushManager = {
        getSubscription: async () => (etat.sub && !etat.sub.desabonne ? etat.sub : null),
        subscribe: async (options) => {
            etat.crees.push(options);
            etat.sub = abonnement(`https://push.test/neuf-${etat.crees.length}`, [...options.applicationServerKey]);

            return etat.sub;
        },
    };

    const document = {
        visibilityState: 'visible',
        addEventListener: () => {},
        querySelector: (selecteur) => {
            const nom = selecteur.match(/name="([^"]+)"/)?.[1];
            if (!(nom in metas) || metas[nom] === null) return null;

            return { getAttribute: () => metas[nom], setAttribute: (_, v) => { metas[nom] = v; } };
        },
    };

    const window = { atob: (b) => Buffer.from(b, 'base64').toString('binary'), PushManager: function () {} };

    const contexte = vm.createContext({
        window,
        document,
        navigator: { serviceWorker: { ready: Promise.resolve({ pushManager }) } },
        Notification: { permission, requestPermission: async () => permission },
        PushManager: { supportedContentEncodings: ['aes128gcm'] },
        localStorage: {
            getItem: (k) => stockage[k] ?? null,
            setItem: (k, v) => { stockage[k] = v; },
            removeItem: (k) => { delete stockage[k]; },
        },
        fetch: async (url, init = {}) => {
            appels.push({ url, method: init.method || 'GET', token: init.headers?.['X-CSRF-TOKEN'], body: init.body ? JSON.parse(init.body) : null });
            const r = reponses.shift();
            if (typeof r === 'function') return r();

            return r ?? { ok: true, status: 201, type: 'basic' };
        },
        console: { warn: () => {}, log: console.log },
        JSON, Date, Uint8Array, Promise, Error, setTimeout,
    });
    window.PushManager = contexte.PushManager;
    window.Notification = contexte.Notification;
    contexte.window.navigator = contexte.navigator;

    vm.runInContext(source, contexte);

    return { clubPush: window.clubPush, appels, etat, metas, stockage };
}

const ok = (status = 201) => ({ ok: true, status, type: 'basic' });
const ko = (status) => ({ ok: false, status, type: 'basic' });

// L'appel de resynchronisation lancé au chargement tourne en tâche de fond : on le laisse finir.
const laisserFinir = () => new Promise((r) => setTimeout(r, 0));

test('« activées » exige que le serveur confirme l\'abonnement', async () => {
    const { clubPush, appels } = charger({ sub: abonnement('https://push.test/a'), stockage: { 'clubPush.sync': JSON.stringify({ key: '7|https://push.test/a', at: Date.now() }) } });
    await laisserFinir();

    assert.equal(await clubPush.getState(), 'on');
    assert.equal(appels.at(-1).url, '/push/subscriptions');
    assert.equal(appels.at(-1).method, 'POST');
    assert.equal(appels.at(-1).body.endpoint, 'https://push.test/a');
});

test('serveur injoignable : l\'interrupteur passe « à réparer », pas « activées »', async () => {
    const frais = { 'clubPush.sync': JSON.stringify({ key: '7|https://push.test/a', at: Date.now() }) };
    const { clubPush } = charger({ sub: abonnement('https://push.test/a'), stockage: frais, reponses: [ko(500)] });
    await laisserFinir();

    assert.equal(await clubPush.getState(), 'repair');
});

test('jeton CSRF périmé (419) : un jeton frais est demandé, puis l\'appel est rejoué une fois', async () => {
    const { clubPush, appels } = charger({
        permission: 'granted',
        reponses: [ko(419), { ok: true, status: 200, json: async () => ({ token: 'JETON-FRAIS' }) }, ok()],
    });

    assert.equal(await clubPush.enable(), 'on');
    assert.deepEqual(appels.map((a) => `${a.method} ${a.url}`), ['POST /push/subscriptions', 'GET /push/jeton', 'POST /push/subscriptions']);
    assert.equal(appels[0].token, 'JETON-VIEUX');
    assert.equal(appels[2].token, 'JETON-FRAIS');
});

test('session perdue (redirection vers la connexion) : échec explicite, pas un faux succès', async () => {
    const { clubPush } = charger({ reponses: [{ ok: false, status: 0, type: 'opaqueredirect' }] });

    await assert.rejects(clubPush.enable(), (e) => e.kind === 'session');
    assert.match(clubPush.messageFor({ kind: 'session' }), /session a expiré/);
});

test('réseau coupé : le message le dit', async () => {
    const { clubPush } = charger({ reponses: [() => { throw new TypeError('Failed to fetch'); }] });

    await assert.rejects(clubPush.enable(), (e) => e.kind === 'network' && /connexion/.test(clubPush.messageFor(e)));
});

test('coupure : même si le serveur refuse, l\'appareil est désabonné', async () => {
    const sub = abonnement('https://push.test/a');
    const { clubPush, appels } = charger({ sub, stockage: { 'clubPush.sync': JSON.stringify({ key: '7|https://push.test/a', at: Date.now() }) }, reponses: [ko(500)] });
    await laisserFinir();

    assert.equal(await clubPush.disable(), 'off');
    assert.equal(appels[0].method, 'DELETE');
    assert.equal(sub.desabonne, true);
});

test('clé VAPID changée : réabonnement avec la nouvelle clé, sans invite', async () => {
    const ancien = abonnement('https://push.test/ancien', [9, 9, 9]);
    const { etat, appels } = charger({ sub: ancien });
    await laisserFinir();

    assert.equal(ancien.desabonne, true);
    assert.equal(etat.crees.length, 1);
    assert.deepEqual([...etat.crees[0].applicationServerKey], CLE_OCTETS);
    assert.equal(appels.at(-1).body.endpoint, 'https://push.test/neuf-1');
});

test('resynchronisation : rien dans la journée, repart si un autre compte se connecte', async () => {
    const stockage = { 'clubPush.sync': JSON.stringify({ key: '7|https://push.test/a', at: Date.now() - 60_000 }) };

    const meme = charger({ sub: abonnement('https://push.test/a'), stockage: { ...stockage } });
    await laisserFinir();
    assert.equal(meme.appels.length, 0);

    const autre = charger({ sub: abonnement('https://push.test/a'), stockage: { ...stockage }, utilisateur: '8' });
    await laisserFinir();
    assert.equal(autre.appels.length, 1);
    assert.equal(autre.appels[0].url, '/push/subscriptions');
});

test('resynchronisation : relancée après un jour, jamais sans compte connecté', async () => {
    const vieux = { 'clubPush.sync': JSON.stringify({ key: '7|https://push.test/a', at: Date.now() - 25 * 3600_000 }) };

    const connecte = charger({ sub: abonnement('https://push.test/a'), stockage: vieux });
    await laisserFinir();
    assert.equal(connecte.appels.length, 1);

    const invite = charger({ sub: abonnement('https://push.test/a'), stockage: { ...vieux }, utilisateur: null });
    await laisserFinir();
    assert.equal(invite.appels.length, 0);
});
