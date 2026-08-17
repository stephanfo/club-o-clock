<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Capture / retrait d'un abonnement Web Push pour l'utilisateur courant (J8.6, cadrage §6.3).
// Appelé en fetch same-origin par le helper push du front au moment où l'utilisateur active ou
// coupe les notifications sur l'appareil (onglet Notifs du profil).
class PushSubscriptionController extends Controller
{
    /** Enregistre (ou met à jour) l'abonnement de cet appareil. Idempotent par endpoint. */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        $endpoint = $data['endpoint'];

        PushSubscription::updateOrCreate(
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
}
