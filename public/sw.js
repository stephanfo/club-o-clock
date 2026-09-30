// Service worker maison — squelette J0 (cadrage §4.1, §6.6).
// V1 cible : cache-first sur l'app-shell + offline-lecture du planning de la semaine.
// À J0 on pose la coquille (install/activate/fetch) ; la stratégie de cache fine vient avec le planning (J1).

const CACHE = 'club-shell-v1';
const SHELL = ['/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // On ne touche qu'au GET same-origin de navigation : network-first avec repli cache (offline-lecture).
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
        );
    }
});

// --- Web Push (J8.6) ---
// Le payload est rendu côté serveur par NotificationRenderer : { title, body, url, icon }.
// `icon` est résolu par le serveur (icône du club ou jeu livré) : ce fichier est STATIQUE, il ne
// peut pas lire l'état de l'instance. Repli en dur si la clé manque — une ligne d'outbox mise en
// file avant cette version n'en a pas.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Notification', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Notification';
    const options = {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192.png',
        data: { url: data.url || '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Le service push a renouvelé l'abonnement (Chrome/FCM le fait sans prévenir) : on transmet le
// nouvel endpoint au serveur, sinon il continue d'écrire à l'ancien et plus rien n'arrive (#96).
// Pas de page ici pour lire la balise CSRF : on demande un jeton à /push/jeton, avec le cookie de
// session. En cas d'échec, la resynchronisation à l'ouverture de l'app (push.js) prend le relais.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        let sub = event.newSubscription || await self.registration.pushManager.getSubscription();
        const key = event.oldSubscription && event.oldSubscription.options
            && event.oldSubscription.options.applicationServerKey;
        if (!sub && key) {
            sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
        }
        if (!sub) return;

        const jeton = await fetch('/push/jeton', { redirect: 'manual', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!jeton.ok) return;
        const { token } = await jeton.json();

        const json = sub.toJSON();
        await fetch('/push/subscriptions', {
            method: 'POST',
            redirect: 'manual',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                keys: json.keys,
                contentEncoding: ((self.PushManager && self.PushManager.supportedContentEncodings) || ['aesgcm'])[0],
            }),
        });
    })().catch((e) => console.warn('[sw] réabonnement reporté à la prochaine ouverture', e)));
});

// Clic sur la notif : focus un onglet ouvert sur l'URL cible, sinon en ouvre un.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            // On compare les CHEMINS, pas les URL entières : une égalité stricte ne matchait presque
            // jamais (slash final, query, hash), donc on ouvrait une fenêtre de plus à chaque clic
            // au lieu de revenir sur celle qui était déjà là.
            const cible = new URL(url, self.location.origin);

            const notres = clients.filter((client) => {
                if (!('focus' in client)) return false;
                try {
                    return new URL(client.url).origin === cible.origin;
                } catch (e) {
                    return false;
                }
            });

            // DEUX passes, et pas une boucle qui tranche fenêtre par fenêtre : la première venue au
            // chemin différent était détournée par navigate() alors qu'une autre était peut-être déjà
            // sur la cible. Avec deux fenêtres ouvertes, le planning (ou un formulaire à moitié
            // rempli) partait ailleurs pendant que la bonne page restait en arrière-plan.
            const surLaCible = notres.find((client) => {
                try {
                    return new URL(client.url).pathname === cible.pathname;
                } catch (e) {
                    return false;
                }
            });
            if (surLaCible) {
                return surLaCible.focus();
            }

            // Aucune fenêtre sur la cible : on en réutilise une — cohérent avec launch_handler:
            // navigate-existing.
            const aNaviguer = notres.find((client) => 'navigate' in client);
            if (aNaviguer) {
                return aNaviguer.navigate(cible.href).then((c) => (c ? c.focus() : null));
            }

            return self.clients.openWindow(cible.href);
        })
    );
});
