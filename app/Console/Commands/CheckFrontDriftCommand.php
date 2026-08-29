<?php

namespace App\Console\Commands;

use App\Support\FrontBuild;
use Illuminate\Console\Command;

// Garde-fou contre le déploiement d'un bundle périmé (cf. App\Support\FrontBuild pour le pourquoi).
//
// Trois issues, et la nuance entre les deux premières est tout l'intérêt de la commande :
//
//  • Pas de bundle du tout → on passe. C'est l'état normal d'un clone frais et de la CI, qui ne
//    buildent pas le front : il n'y a rien à mettre en doute.
//  • Un bundle SANS empreinte → on échoue. Le bundle existe mais on ne sait pas de quelles sources
//    il sort. Un « on ne sait pas » ne doit jamais se lire comme un « c'est bon » : c'est
//    exactement ainsi qu'un CSS périmé part en production sans un seul message.
//  • Un bundle dont l'empreinte ne correspond plus → on échoue en nommant les fichiers en cause.
class CheckFrontDriftCommand extends Command
{
    protected $signature = 'front:check-drift';

    protected $description = 'Vérifie que le bundle de public/build/ correspond aux sources front actuelles';

    public function handle(): int
    {
        $front = $this->laravel->make(FrontBuild::class);

        if (! $front->isBuilt()) {
            $this->components->info('Pas de bundle front à contrôler (public/build/ absent) — rien à comparer.');

            return self::SUCCESS;
        }

        $stamp = $front->readStamp();

        if ($stamp === null) {
            $this->components->error('Bundle front sans empreinte : impossible de savoir s\'il est à jour.');
            $this->line('');
            $this->components->warn('Reconstruis-le : npm run build');

            return self::FAILURE;
        }

        $drift = $front->drift($stamp);

        if ($drift === []) {
            $this->components->info('Front cohérent : le bundle correspond aux sources.');

            return self::SUCCESS;
        }

        $this->components->error('Le bundle de public/build/ ne correspond plus aux sources front.');
        $this->line('');
        foreach (array_slice($drift, 0, 40) as $line) {
            $this->line('  '.$line);
        }
        $this->line('');
        $this->components->warn('Reconstruis le front AVANT de déployer : npm run build');
        $this->components->warn('Sans ça, le serveur continuera de servir l\'ancien CSS/JS, sans la moindre erreur.');

        return self::FAILURE;
    }
}
