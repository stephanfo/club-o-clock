<?php

namespace App\Console\Commands;

use App\Services\DuePeriodGuard;
use Illuminate\Console\Command;

/**
 * Enveloppe rattrapable de `model:prune` (élagage des jetons d'auth expirés/consommés).
 *
 * `model:prune` appartient au framework : on ne lui ajoute pas d'option maison. Cette enveloppe
 * porte la garde d'échéance, pour la même raison que les autres tâches périodiques — sur un cron
 * horaire à minute imposée, une tâche planifiée `daily()` (soit `0 0 * * *`) peut n'être vue
 * *jamais*, la minute 0 tombant dans le trou de couverture de la boucle (cf. RunCronLoopCommand).
 *
 * Planifiée toutes les 5 min et gardée à la journée, elle dispose de nombreuses occasions de
 * rattraper son échéance au lieu d'une seule — y compris après une boucle interrompue.
 */
class PruneTokensCommand extends Command
{
    protected $signature = 'club:prune-tokens
        {--if-due : N\'exécuter que si l\'élagage du jour n\'a pas déjà eu lieu}';

    protected $description = 'Élague les jetons d\'authentification expirés ou consommés (enveloppe rattrapable de model:prune).';

    public function handle(DuePeriodGuard $guard): int
    {
        if ($this->option('if-due')) {
            $guard->runIfDue(
                'prune-tokens',
                DuePeriodGuard::dailyPeriod(timezone: config('app.timezone') ?: 'UTC'),
                fn () => $this->elaguer(),
            );

            return self::SUCCESS;
        }

        $this->elaguer();

        return self::SUCCESS;
    }

    /** Retourne true si l'élagage s'est terminé sans erreur (seul un succès honore l'échéance). */
    private function elaguer(): bool
    {
        return $this->callSilent('model:prune') === self::SUCCESS;
    }
}
