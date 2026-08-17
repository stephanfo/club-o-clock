<?php

namespace App\Notifications\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

// Envoi Web Push réel via minishlink/web-push (cadrage §6.3 « VAPID natif »). Signe la requête avec
// les clés VAPID de l'instance et POST l'endpoint du navigateur. Aucun service tiers, aucun flux
// hors-UE : le navigateur parle directement à son propre service push.
class MinishlinkWebPushSender implements WebPushSender
{
    public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult
    {
        $webPush = new WebPush(['VAPID' => $this->vapidAuth()]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
                'contentEncoding' => $subscription->content_encoding,
            ]),
            $payloadJson,
        );

        if ($report->isSuccess()) {
            return PushDeliveryResult::delivered();
        }

        // 404/410 : l'abonnement n'existe plus côté navigateur → à purger.
        return $report->isSubscriptionExpired()
            ? PushDeliveryResult::expired()
            : PushDeliveryResult::failed();
    }

    /** @return array{subject:string,publicKey:string,privateKey:string} */
    private function vapidAuth(): array
    {
        $vapid = config('club.vapid');

        if (empty($vapid['public_key']) || empty($vapid['private_key']) || empty($vapid['subject'])) {
            throw new RuntimeException('Clés VAPID absentes : lance `php artisan club:vapid-keys` et renseigne le .env.');
        }

        return [
            'subject' => $vapid['subject'],
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
        ];
    }
}
