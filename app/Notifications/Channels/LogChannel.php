<?php

namespace App\Notifications\Channels;

use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\Log;

// Driver d'envoi par défaut jusqu'à la livraison réelle (J8.6) : journalise l'envoi et réussit.
// Permet d'exercer tout le chemin outbox + drain sans dépendance externe (clés VAPID / email UE).
class LogChannel implements NotificationChannel
{
    public function send(NotificationOutbox $line): bool
    {
        Log::info('notification.send', [
            'id' => $line->id,
            'type' => $line->type,
            'channel' => $line->channel,
            'user_id' => $line->user_id,
        ]);

        return true;
    }
}
