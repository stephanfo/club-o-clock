<?php

namespace App\Notifications\Channels;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Notifications\NotificationRenderer;
use App\Notifications\Push\WebPushSender;

// Livraison réelle du canal « push » (J8.6, cadrage §6.3). Rend la ligne (titre/corps/lien) puis
// pousse le payload à CHAQUE abonnement de l'utilisateur (multi-appareils). Les endpoints morts
// (404/410) sont purgés au passage. La signature/réseau vit derrière WebPushSender (testable).
class PushChannel implements NotificationChannel
{
    public function __construct(
        private WebPushSender $sender,
        private NotificationRenderer $renderer,
    ) {}

    public function send(NotificationOutbox $line): bool
    {
        $user = $line->user;

        if ($user === null || $user->anonymized_at !== null) {
            // Destinataire disparu OU tombstone RGPD : rien à pousser, terminal. La garde tombstone
            // est une défense en profondeur — confirmDeletion purge subscriptions et lignes pending,
            // mais une ligne en vol (drain concurrent) ne doit jamais contacter un compte effacé.
            return true;
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
                $delivered++;
            } else {
                $transientFailures++;
            }
        }

        if ($delivered > 0) {
            return true;
        }

        // Échec transitoire sur des abonnements vivants → on laisse le drain retenter.
        if ($transientFailures > 0) {
            return false;
        }

        // Aucun abonnement (ou tous purgés) : rien à envoyer, inutile de retenter → terminal.
        return true;
    }
}
