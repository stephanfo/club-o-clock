<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Capture / retrait d'un abonnement Web Push pour l'utilisateur courant (J8.6, cadrage §6.3).
// Appelé en fetch same-origin par le helper push du front au moment où l'utilisateur active ou
// coupe les notifications sur l'appareil (onglet Notifs du profil).
class PushSubscriptionController extends Controller
{
    /**
     * Enregistre (ou met à jour) l'abonnement de cet appareil. Idempotent par endpoint : c'est aussi
     * la route de resynchronisation, rappelée par le navigateur à l'ouverture de l'app (#96).
     *
     * Un endpoint déjà rattaché à un autre compte passe au compte courant : l'endpoint identifie le
     * navigateur, pas la personne, et seul ce navigateur le connaît. Sur un appareil partagé, les
     * alertes suivent donc le compte connecté, au lieu d'arriver à quelqu'un qui s'en est déconnecté.
     */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        $endpoint = $data['endpoint'];

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($endpoint)],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        // Une resynchronisation à l'identique ne salit aucun champ : on date quand même le passage,
        // pour que updated_at dise quand l'appareil a confirmé son abonnement pour la dernière fois.
        if (! $subscription->wasRecentlyCreated && ! $subscription->wasChanged()) {
            $subscription->touch();
        }

        return response()->noContent(Response::HTTP_CREATED);
    }

    /** Supprime l'abonnement de cet appareil (désactivation côté navigateur). */
    public function destroy(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->delete();

        return response()->noContent();
    }

    /**
     * Jeton CSRF frais. Une PWA restée ouverte au-delà de la durée de session garde un jeton périmé :
     * le POST/DELETE ci-dessus répond 419. Le front en redemande un ici puis retente une fois (#96),
     * au lieu d'échouer sans rien dire. Même besoin pour le service worker, qui n'a pas de page où
     * lire la balise meta quand le navigateur renouvelle l'abonnement (pushsubscriptionchange).
     */
    public function token(): JsonResponse
    {
        return response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store');
    }
}
