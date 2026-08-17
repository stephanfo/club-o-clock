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

async function post(url, method, body) {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': meta('csrf-token') || '',
            'Accept': 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) {
        throw new Error(`push sync failed: ${res.status}`);
    }
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

    // 'unsupported' | 'denied' | 'on' | 'off'
    async getState() {
        if (!this.isSupported()) return 'unsupported';
        if (Notification.permission === 'denied') return 'denied';

        try {
            const reg = await swReady();
            const sub = await reg.pushManager.getSubscription();
            return sub ? 'on' : 'off';
        } catch (e) {
            // SW jamais prêt → on ne peut pas gérer le push sur cet appareil.
            return 'unsupported';
        }
    },

    async enable() {
        if (!this.isSupported()) return 'unsupported';

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off';

        const reg = await swReady();
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(meta('vapid-public-key')),
            });
        }

        const json = sub.toJSON();
        await post('/push/subscriptions', 'POST', {
            endpoint: sub.endpoint,
            keys: json.keys,
            contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
        });

        return 'on';
    },

    async disable() {
        if (!this.isSupported()) return 'unsupported';

        const reg = await swReady();
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            await post('/push/subscriptions', 'DELETE', { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }

        return 'off';
    },
};

window.clubPush = clubPush;
