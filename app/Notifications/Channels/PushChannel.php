<?php

namespace App\Notifications\Channels;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\PushSubscription;
use App\Notifications\NotificationRenderer;
use App\Notifications\Push\WebPushSender;
use Illuminate\Support\Carbon;

// Livraison réelle du canal « push » (J8.6, cadrage §6.3). Rend la ligne (titre/corps/lien) puis
// pousse le payload à CHAQUE abonnement de l'utilisateur (multi-appareils). Les endpoints morts
// (404/410) sont purgés au passage. La signature/réseau vit derrière WebPushSender (testable).
//
// Santé par appareil (#97) : chaque essai met à jour last_success_at / last_failure_at /
// failure_count. Un appareil qui échoue sans jamais être déclaré mort (service push qui répond 5xx
// indéfiniment, endpoint laissé à l'abandon) est purgé après PURGE_AFTER_FAILURES échecs
// consécutifs sans aucun succès depuis PURGE_AFTER_DAYS jours — sinon il ferait retenter chaque
// notification de son propriétaire jusqu'à `failed`, y compris quand un autre appareil la reçoit.
class PushChannel implements NotificationChannel
{
    public const PURGE_AFTER_FAILURES = 5;

    public const PURGE_AFTER_DAYS = 30;

    public function __construct(
        private WebPushSender $sender,
        private NotificationRenderer $renderer,
    ) {}

    public function send(NotificationOutbox $line): DeliveryOutcome
    {
        $user = $line->user;

        if ($user === null || $user->anonymized_at !== null) {
            // Destinataire disparu OU tombstone RGPD : rien à pousser, terminal. La garde tombstone
            // est une défense en profondeur — confirmDeletion purge subscriptions et lignes pending,
            // mais une ligne en vol (drain concurrent) ne doit jamais contacter un compte effacé.
            return DeliveryOutcome::NoTarget;
        }

        // L'icône rejoint le payload ici, et non dans le renderer, qui sert aussi l'email : c'est
        // une donnée d'affichage propre au push. public/sw.js est un fichier STATIQUE — il ne peut
        // pas lire ClubSettings (cadrage §7.16), donc le serveur lui transmet l'URL déjà résolue,
        // le service worker gardant un repli en dur si la clé manque (payload d'une version
        // antérieure encore en vol dans l'outbox).
        $payload = json_encode([
            ...$this->renderer->render($line),
            'icon' => ClubSettings::current()->pwaIconUrl('icon_192'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $delivered = 0;
        $transientFailures = 0;

        foreach ($user->pushSubscriptions as $subscription) {
            $result = $this->sender->send($subscription, $payload);

            if ($result->expired) {
                $subscription->delete(); // endpoint mort : on purge, pas de retry possible.
            } elseif ($result->delivered) {
                $subscription->forceFill(['last_success_at' => Carbon::now(), 'failure_count' => 0])->save();
                $delivered++;
            } elseif ($this->recordFailure($subscription)) {
                $transientFailures++;
            }
        }

        if ($delivered > 0) {
            return DeliveryOutcome::Delivered;
        }

        // Échec transitoire sur des abonnements vivants → on laisse le drain retenter.
        if ($transientFailures > 0) {
            return DeliveryOutcome::Retry;
        }

        // Aucun abonnement (ou tous purgés) : personne à qui l'envoyer → terminal, et dit comme tel.
        return DeliveryOutcome::NoTarget;
    }

    /**
     * Compte un échec transitoire ; purge l'appareil s'il a épuisé son crédit. Renvoie true si
     * l'appareil reste abonné (donc vaut un nouvel essai).
     */
    private function recordFailure(PushSubscription $subscription): bool
    {
        $failures = $subscription->failure_count + 1;
        $lastProof = $subscription->last_success_at ?? $subscription->created_at;

        if ($failures >= self::PURGE_AFTER_FAILURES
            && ($lastProof === null || $lastProof->lt(Carbon::now()->subDays(self::PURGE_AFTER_DAYS)))) {
            $subscription->delete();

            return false;
        }

        $subscription->forceFill(['last_failure_at' => Carbon::now(), 'failure_count' => $failures])->save();

        return true;
    }
}
