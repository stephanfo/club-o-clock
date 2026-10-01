<?php

namespace App\Notifications\Channels;

use App\Models\NotificationOutbox;

// Driver d'envoi abstrait (cadrage §7.14). Le drain ne connaît que ce contrat ; la livraison réelle
// (VAPID web push, email transactionnel UE) arrive en J8.6 derrière la même interface.
interface NotificationChannel
{
    /**
     * Tente l'envoi d'une ligne d'outbox. Retry : le drain programmera un nouvel essai ; NoTarget :
     * personne à qui l'envoyer, terminal. Peut aussi lever : le drain traite l'exception comme un
     * échec transitoire.
     */
    public function send(NotificationOutbox $line): DeliveryOutcome;
}
