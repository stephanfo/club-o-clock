<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Remise à zéro nocturne de l'instance de démonstration
|--------------------------------------------------------------------------
|
| Point d'entrée d'une SECONDE tâche cron, à créer **uniquement sur l'instance de démonstration**
| (plan open source OS7), en plus de celle de `cron.php`. À planifier une fois par jour, dans une
| heure creuse — l'heure exacte n'a aucune importance, la minute encore moins.
|
| Pourquoi une tâche distincte plutôt qu'une entrée du planificateur Laravel : `demo:reset` exécute
| `migrate:fresh`, qui détruit la base — donc aussi toute trace en base indiquant qu'il vient de
| tourner. Piloté par le planificateur, qui repasse toutes les 5 minutes, il se rejouerait en
| boucle : il faudrait un marqueur hors base, une fenêtre horaire et un verrou pour l'en empêcher.
| Confier la périodicité au cron de l'hébergeur supprime le problème au lieu de le contenir —
| OVH garantit une exécution par heure, et l'on n'en demande qu'une par jour.
|
| Sur une instance de club, cette tâche n'existe pas. Et si elle était créée par erreur, la
| commande refuserait de s'exécuter : son garde-fou `DEMO_MODE` reste le verrou qui protège
| vraiment, au plus près de la destruction.
|
| Sur VPS, une ligne de crontab suffit : `0 4 * * * php /chemin/artisan demo:reset`.
*/

// Ce fichier ne doit JAMAIS être atteignable par HTTP : il détruit la base et la reconstruit.
// Sur mutualisé, la racine web est celle du dépôt — le .htaccess le bloque déjà, ce garde tient
// même s'il est mal déployé.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__);

$_SERVER['argv'] = [
    __DIR__.'/artisan',
    'demo:reset',
    '--no-interaction',
];
$_SERVER['argc'] = count($_SERVER['argv']);

require __DIR__.'/artisan';
