<?php

namespace App\Notifications\Channels;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

// Résout un canal ('push' | 'email') vers son driver d'envoi, d'après config('club.notifications.
// channels'). Par défaut LogChannel pour les deux (J8.1) ; la livraison réelle (J8.6) ne fait que
// remplacer la classe ciblée en config, sans toucher au drain.
class ChannelManager
{
    public function __construct(private Container $app) {}

    public function driver(string $channel): NotificationChannel
    {
        $class = config("club.notifications.channels.$channel");

        if ($class === null) {
            throw new RuntimeException("Aucun driver configuré pour le canal '$channel'.");
        }

        return $this->app->make($class);
    }
}
