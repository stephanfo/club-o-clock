<?php

use App\Notifications\Channels\LogChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | Email d'amorçage admin
    |--------------------------------------------------------------------------
    |
    | Le premier compte créé avec cet email reçoit le rôle « admin » (PRD §4.1.4,
    | cadrage §7.3). Indispensable au démarrage d'une instance (one-instance-per-club).
    | Renseigné dans le .env de chaque déploiement.
    |
    */

    'bootstrap_admin_email' => env('BOOTSTRAP_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Notifications — drivers d'envoi par canal (PRD §4.15, cadrage §7.14)
    |--------------------------------------------------------------------------
    |
    | Chaque canal ('push' | 'email') est résolu vers un driver implémentant
    | NotificationChannel. Par défaut LogChannel (journalise + réussit) tant que
    | la livraison réelle n'est pas branchée (J8.6 : VAPID web push + email UE).
    | La bascule production = remplacer la classe ciblée, sans toucher au drain.
    |
    */

    'notifications' => [
        'channels' => [
            // `?:` et non le 2e argument de `env()` : une variable PRÉSENTE MAIS VIDE (c'est le cas
            // dans .env.example, et donc en CI) ne déclenche pas le défaut d'`env()` — elle le
            // remplace par ''. Le drainer tentait alors d'instancier une classe vide, l'envoi
            // levait, et la ligne repartait en backoff `pending` : le canal était réputé « branché »
            // tout en n'envoyant rien. Le commentaire ci-dessus promet « Vide = LogChannel » ; ceci
            // le rend vrai.
            'push' => env('NOTIF_PUSH_DRIVER') ?: LogChannel::class,
            'email' => env('NOTIF_EMAIL_DRIVER') ?: LogChannel::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Push — clés VAPID (PRD §4.15, cadrage §6.3 « Push VAPID natif »)
    |--------------------------------------------------------------------------
    |
    | Paire de clés VAPID propre à l'instance (standard ouvert, aucun service
    | tiers, aucun flux hors-UE). Générée une fois par `php artisan club:vapid-keys`
    | puis collée dans le .env du déploiement. La clé publique est exposée au front
    | (souscription PushManager) ; la privée signe les requêtes Web Push côté PHP.
    | « subject » = contact (mailto: ou URL) imposé par la spec VAPID.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mode démonstration (plan open source OS7)
    |--------------------------------------------------------------------------
    |
    | Instance publique vitrine, réinitialisée chaque nuit. À n'activer QUE sur un
    | déploiement dédié : l'écran de connexion y affiche les identifiants de démo,
    | donc n'importe quel visiteur peut agir en admin.
    |
    | Ce drapeau n'est pas qu'un affichage : App\Support\DemoMode IMPOSE les
    | garde-fous d'envoi (cf. AppServiceProvider::register). Il déverrouille aussi
    | `php artisan demo:reset`, qui refuse de s'exécuter sans lui.
    |
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
    ],

];
