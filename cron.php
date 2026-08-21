<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Point d'entrée du planificateur pour hébergement mutualisé
|--------------------------------------------------------------------------
|
| Le gestionnaire de tâches d'OVH ne sait pointer qu'un FICHIER PHP, sans arguments : impossible
| d'y écrire `artisan club:cron-boucle --duree=55`. Or `artisan` construit sa commande à partir de
| `argv` — appelé sans arguments, il n'exécute rien d'utile.
|
| Ce fichier comble l'écart : il pose les arguments qu'artisan attend, puis délègue. Aucune logique
| métier ici, et surtout pas de bootstrap Laravel maison — on reste sur le chemin d'exécution
| officiel pour ne pas diverger d'`artisan` à la première mise à jour du framework.
|
| Sur VPS, ce fichier est inutile : la crontab appelle directement `php artisan schedule:run`.
|
| Configuration au manager OVH : une tâche horaire pointant ce fichier (cf. INSTALL §5.4).
*/

// Ce fichier ne doit JAMAIS être atteignable par HTTP : le déclencher depuis le web lancerait
// ~55 min de traitement par requête, soit un déni de service trivial. Sur mutualisé, la racine
// web est celle du dépôt : le .htaccess le bloque déjà, ce garde tient même s'il est mal déployé.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Le cron ne garantit pas le répertoire courant : artisan et l'autoloader se résolvent en absolu.
chdir(__DIR__);

$_SERVER['argv'] = [
    __DIR__.'/artisan',
    'club:cron-boucle',
    '--duree=55',
    '--no-interaction',
];
$_SERVER['argc'] = count($_SERVER['argv']);

require __DIR__.'/artisan';
