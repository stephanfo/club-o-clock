<?php

namespace App\Notifications\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
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

        return self::resultFor($report);
    }

    /**
     * Classe la réponse du service push. 404/410 : l'abonnement n'existe plus. 401/403 : le service
     * refuse notre signature VAPID pour cet abonnement — cas des clés changées, où l'abonnement a
     * été créé avec l'ancienne clé publique et ne sera plus jamais accepté (#96). Les deux sont
     * purgés : retenter ne peut pas réussir, et le navigateur se réabonne à la prochaine ouverture
     * de l'app (resynchronisation de resources/js/push.js). Le reste (réseau, 429, 5xx) est
     * transitoire et laissé au drain.
     */
    public static function resultFor(MessageSentReport $report): PushDeliveryResult
    {
        if ($report->isSuccess()) {
            return PushDeliveryResult::delivered();
        }

        $status = $report->getResponse()?->getStatusCode();

        return in_array($status, [401, 403, 404, 410], true)
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
