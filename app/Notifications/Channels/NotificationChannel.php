<?php

namespace App\Notifications\Channels;

use App\Models\NotificationOutbox;

// Driver d'envoi abstrait (cadrage §7.14). Le drain ne connaît que ce contrat ; la livraison réelle
// (VAPID web push, email transactionnel UE) arrive en J8.6 derrière la même interface.
interface NotificationChannel
{
    /**
     * Tente l'envoi d'une ligne d'outbox. Retourne true si délivré, false sinon (le drain
     * programmera un retry). Peut aussi lever : le drain traite l'exception comme un échec.
     */
    public function send(NotificationOutbox $line): bool;
}
