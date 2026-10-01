// Helper Web Push côté navigateur (J8.6). Pilote l'abonnement PushManager et le synchronise avec
// le serveur (POST/DELETE /push/subscriptions). Exposé sur window.clubPush pour l'onglet Notifs du
// profil (toggle Alpine « activer les notifs sur cet appareil »). Aucune dépendance : Web Push natif.

function meta(name) {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') : null;
}

function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(normalized);
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

// navigator.serviceWorker.ready ne résout JAMAIS si aucun SW ne s'active (échec d'enregistrement) :
// on borne l'attente pour ne pas figer le toggle « Vérification… » indéfiniment.
function swReady(timeoutMs = 5000) {
    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => setTimeout(() => reject(new Error('service worker indisponible')), timeoutMs)),
    ]);
}

// Échec typé : l'onglet Notifs en tire un message (messageFor) au lieu d'un retour muet (#96).
class PushError extends Error {
    constructor(kind, status = null) {
        super(`push ${kind}${status ? ` ${status}` : ''}`);
        this.kind = kind; // 'network' | 'session' | 'server' | 'browser'
        this.status = status;
    }
}

// Une PWA restée ouverte plus longtemps que la session garde un jeton CSRF périmé (419). On en
// redemande un : avec le cookie « se souvenir de moi », le GET reconnecte le compte au passage.
async function refreshCsrf() {
    let res;
    try {
        res = await fetch('/push/jeton', { redirect: 'manual', cache: 'no-store', headers: { 'Accept': 'application/json' } });
    } catch (e) {
        throw new PushError('network');
    }
    // Redirection vers la connexion (réponse opaque, ok = false) : la session est vraiment perdue.
    if (!res.ok) throw new PushError('session', res.status || null);
    const { token } = await res.json();
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
}

async function post(url, method, body, retried = false) {
    let res;
    try {
        res = await fetch(url, {
            method,
            // Sans « manual », une session expirée redirige vers /login et fetch suit : la page de
            // connexion répond 200 et l'appel passait pour réussi.
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': meta('csrf-token') || '',
                'Accept': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch (e) {
        throw new PushError('network');
    }
    if (res.status === 419 && !retried) {
        await refreshCsrf();
        return post(url, method, body, true);
    }
    if (res.type === 'opaqueredirect' || res.status === 419 || res.status === 401) {
        throw new PushError('session', res.status || null);
    }
    if (!res.ok) {
        throw new PushError('server', res.status);
    }
}

// L'abonnement a-t-il été créé avec la clé VAPID actuelle du serveur ? Après un changement de clés,
// l'ancien est refusé (401/403) à chaque envoi. Navigateur qui n'expose pas la clé : on ne peut pas
// juger, on le garde.
function sameVapidKey(sub) {
    const current = sub.options && sub.options.applicationServerKey;
    if (!current) return true;
    const a = new Uint8Array(current);
    const b = urlBase64ToUint8Array(meta('vapid-public-key'));
    return a.length === b.length && a.every((v, i) => v === b[i]);
}

// Abonnement du navigateur, remis sur la bonne clé VAPID si besoin. La permission étant déjà
// accordée, se réabonner n'affiche aucune invite. `create` : en créer un s'il n'y en a pas.
async function currentSubscription(reg, create) {
    let sub = await reg.pushManager.getSubscription();
    if (sub && !sameVapidKey(sub)) {
        // L'ancienne ligne serveur sera purgée au prochain envoi refusé : rien à faire ici.
        await sub.unsubscribe();
        sub = null;
        create = true;
    }
    if (!sub && create) {
        try {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(meta('vapid-public-key')),
            });
        } catch (e) {
            throw new PushError('browser');
        }
    }
    return sub;
}

// Resynchronisation : au plus une fois par jour et par appareil, sauf si l'endpoint ou le compte
// connecté ont changé depuis. Tampon en localStorage, lu et écrit sous try/catch (navigation
// privée, stockage bloqué) : sans lui, on resynchronise simplement à chaque ouverture.
const STAMP = 'clubPush.sync';
const DAY = 24 * 60 * 60 * 1000;

function stampFor(sub) {
    return `${meta('club-user') || ''}|${sub.endpoint}`;
}

function isFresh(sub) {
    try {
        const saved = JSON.parse(localStorage.getItem(STAMP) || 'null');
        return !!saved && saved.key === stampFor(sub) && Date.now() - saved.at < DAY;
    } catch (e) {
        return false;
    }
}

function markSynced(sub) {
    try {
        localStorage.setItem(STAMP, JSON.stringify({ key: stampFor(sub), at: Date.now() }));
    } catch (e) {
        // Stockage indisponible : la prochaine ouverture resynchronisera, sans autre conséquence.
    }
}

async function register(sub) {
    const json = sub.toJSON();
    await post('/push/subscriptions', 'POST', {
        endpoint: sub.endpoint,
        keys: json.keys,
        contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
    });
    markSynced(sub);
}

const clubPush = {
    // Le push natif requiert SW + PushManager + Notification, et une clé VAPID configurée côté serveur.
    isSupported() {
        return (
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window &&
            !!meta('vapid-public-key')
        );
    },

    // 'unsupported' | 'denied' | 'on' | 'off' | 'repair'
    // « on » exige que le SERVEUR connaisse l'abonnement : l'écran affichait « Activées ici » d'après
    // le seul navigateur, alors que le serveur l'avait perdu (#96). On le lui renvoie donc ici
    // (idempotent) ; si ça échoue, « repair » invite à réactiver.
    async getState() {
        if (!this.isSupported()) return 'unsupported';
        if (Notification.permission === 'denied') return 'denied';

        let reg;
        try {
            reg = await swReady();
        } catch (e) {
            // SW jamais prêt → on ne peut pas gérer le push sur cet appareil.
            return 'unsupported';
        }

        const sub = await reg.pushManager.getSubscription();
        if (!sub || Notification.permission !== 'granted') return 'off';

        try {
            await register(await currentSubscription(reg, true));
            return 'on';
        } catch (e) {
            console.warn('[push] abonnement non confirmé par le serveur', e);
            return 'repair';
        }
    },

    async enable() {
        if (!this.isSupported()) return 'unsupported';

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off';

        const reg = await swReady();
        await register(await currentSubscription(reg, true));

        return 'on';
    },

    async disable() {
        if (!this.isSupported()) return 'unsupported';

        const reg = await swReady();
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            try {
                await post('/push/subscriptions', 'DELETE', { endpoint: sub.endpoint });
            } catch (e) {
                // Le serveur n'a pas pu retirer la ligne : on coupe quand même côté navigateur, ce
                // qui est le geste demandé. La ligne orpheline sera purgée au premier envoi (404/410).
                console.warn('[push] retrait serveur impossible, abonnement coupé localement', e);
            }
            try {
                await sub.unsubscribe();
            } catch (e) {
                throw new PushError('browser');
            }
        }
        try {
            localStorage.removeItem(STAMP);
        } catch (e) {
            // Rien à nettoyer si le stockage est indisponible.
        }

        return 'off';
    },

    // Réparation silencieuse à l'ouverture de l'app : aucune invite, aucun message. Ne fait rien sans
    // compte connecté, sans permission accordée ou sans abonnement existant.
    async resync() {
        if (!this.isSupported() || !meta('club-user') || Notification.permission !== 'granted') return;
        try {
            const reg = await swReady();
            const sub = await reg.pushManager.getSubscription();
            if (!sub || (isFresh(sub) && sameVapidKey(sub))) return;
            await register(await currentSubscription(reg, true));
        } catch (e) {
            // Pas de tampon posé : on réessaiera à la prochaine ouverture ; l'onglet Notifs, lui,
            // affichera « à réparer ».
            console.warn('[push] resynchronisation reportée', e);
        }
    },

    // Endpoint de l'abonnement de CET appareil, ou null (#97 : notification de test, repérage dans
    // « Mes appareils »). Ne crée rien et ne demande aucune permission.
    async currentEndpoint() {
        if (!this.isSupported()) return null;
        try {
            const reg = await swReady();
            const sub = await reg.pushManager.getSubscription();
            return sub ? sub.endpoint : null;
        } catch (e) {
            return null;
        }
    },

    // Empreinte SHA-256 de l'endpoint, même calcul que PushSubscription::hashFor : la liste des
    // appareils repère la ligne de cet appareil sans que l'endpoint transite. Null hors contexte
    // sécurisé (crypto.subtle absent) : la liste s'affiche alors sans repère, rien ne casse.
    async currentEndpointHash() {
        const endpoint = await this.currentEndpoint();
        if (!endpoint || !window.crypto || !window.crypto.subtle) return null;
        try {
            const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
            return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
        } catch (e) {
            return null;
        }
    },

    messageFor(e) {
        switch (e && e.kind) {
            case 'network':
                return 'Pas de connexion : réessaie une fois en ligne.';
            case 'session':
                return 'Ta session a expiré : recharge la page, puis réessaie.';
            case 'server':
                return `Le serveur n'a pas pu enregistrer ton choix (erreur ${e.status}). Réessaie dans un instant.`;
            default:
                return 'Le navigateur a refusé l’abonnement : réessaie, ou vérifie ses réglages de notifications.';
        }
    },
};

window.clubPush = clubPush;

// À l'ouverture de l'app, et au retour au premier plan d'une PWA restée en mémoire des jours durant.
clubPush.resync();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') clubPush.resync();
});
