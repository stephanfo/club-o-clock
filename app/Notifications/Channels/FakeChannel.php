<?php

namespace App\Notifications\Channels;

use App\Models\NotificationOutbox;

// Driver d'envoi en mémoire pour les tests : enregistre les lignes « envoyées » et peut simuler
// un échec ($shouldFail) pour exercer le retry/backoff du drain. Jamais utilisé en production.
class FakeChannel implements NotificationChannel
{
    /** @var list<int> ids des lignes passées par send() avec succès */
    public array $sent = [];

    public bool $shouldFail = false;

    /** Simule un destinataire sans appareil ni adresse (#97). */
    public bool $noTarget = false;

    public function send(NotificationOutbox $line): DeliveryOutcome
    {
        if ($this->shouldFail) {
            return DeliveryOutcome::Retry;
        }
        if ($this->noTarget) {
            return DeliveryOutcome::NoTarget;
        }

        $this->sent[] = $line->id;

        return DeliveryOutcome::Delivered;
    }
}
