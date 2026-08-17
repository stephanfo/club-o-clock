<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

// Génère une paire de clés VAPID propre à l'instance (cadrage §6.3, §16 « clés VAPID »). Hors-ligne,
// sans service tiers. À lancer une fois au déploiement ; coller la sortie dans le .env du club.
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'club:vapid-keys';

    protected $description = 'Génère une paire de clés VAPID (web push) à coller dans le .env';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Clés VAPID générées. À reporter dans le .env de cette instance :');
        $this->newLine();
        $this->line('VAPID_SUBJECT=mailto:contact@ton-club.fr');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('La clé privée est un secret : ne la committe jamais. Pour activer l\'envoi réel, '
            .'positionne aussi NOTIF_PUSH_DRIVER=App\\Notifications\\Channels\\PushChannel.');

        return self::SUCCESS;
    }
}
