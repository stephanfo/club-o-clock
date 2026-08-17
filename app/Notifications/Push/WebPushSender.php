<?php

namespace App\Notifications\Push;

use App\Models\PushSubscription;

// Couture d'envoi Web Push : isole PushChannel de la lib réseau (minishlink/web-push). En prod
// l'implémentation signe + POST l'endpoint VAPID ; en test une fake retourne un résultat scénarisé,
// sans dépendance réseau ni clés.
interface WebPushSender
{
    public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult;
}
