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
     * Classe la réponse du service push. 404/410 : l'abonnement n'existe plus → purgé.
     *
     * 401/403 (signature VAPID refusée) ne purgent PAS : la même réponse vient d'une clé changée
     * (abonnement créé avec l'ancienne clé, cas #96) ET d'une erreur de configuration du serveur
     * (clé ou subject mal saisis, horloge décalée). Dans le second cas, purger supprimait en un
     * passage de drain les abonnements de tout le club, pour une faute qui n'était pas la leur.
     * Traités en échec transitoire, ils comptent dans la santé de l'appareil : l'écran des envois
     * lève l'alerte d'échecs massifs, une configuration corrigée reprend les envois sans geste
     * des adhérents, et un abonnement vraiment périmé tombe sous la purge des 5 échecs sans succès
     * depuis 30 jours (PushChannel) — le navigateur, lui, se réabonne avec la bonne clé dès la
     * prochaine ouverture (resources/js/push.js). Le reste (réseau, 429, 5xx) est transitoire.
     */
    public static function resultFor(MessageSentReport $report): PushDeliveryResult
    {
        if ($report->isSuccess()) {
            return PushDeliveryResult::delivered();
        }

        $status = $report->getResponse()?->getStatusCode();

        return in_array($status, [404, 410], true)
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
