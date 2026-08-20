<?php

use App\Support\MagicLink;
use Illuminate\Contracts\Console\Kernel;

// Génère une URL de connexion à usage unique (harnais E2E, local uniquement).
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Garde-fou : ce harnais ouvre une session sans mot de passe et écrit en base.
// Il ne doit JAMAIS s'exécuter ailleurs qu'en local.
if (! app()->environment('local')) {
    fwrite(STDERR, "refus : harnais E2E réservé à APP_ENV=local\n");
    exit(1);
}
$url = MagicLink::createUrlFor($argv[1]);
// Force l'hôte local : APP_URL peut pointer sur l'IP LAN.
echo preg_replace('#^https?://[^/]+#', 'http://127.0.0.1:8000', $url), PHP_EOL;
