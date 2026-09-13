<?php

namespace App\Console\Commands;

use App\Services\DuePeriodGuard;
use Illuminate\Console\Command;

/**
 * Enveloppe rattrapable de `model:prune` — élagage de TOUT modèle `Prunable` : jetons d'auth
 * expirés/consommés (MagicLinkToken, InvitationToken) et entrées de cache météo dont le créneau
 * est passé (WeatherCacheEntry). Le nom `club:prune-tokens` désigne l'enveloppe historique ; elle
 * ne restreint volontairement aucun modèle (pas de `--model`), tout `Prunable` d'`app/Models` est
 * ramassé.
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

    protected $description = 'Élague les données périmées : jetons d\'authentification et cache météo.';

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

    /**
     * Retourne true si l'élagage s'est terminé sans erreur (seul un succès honore l'échéance).
     *
     * `model:prune` est appelé SANS `--model` : il balaie `app/Models` et ramasse tout ce qui porte
     * le trait. Rendre un modèle élagable suffit donc à l'inscrire ici — ne pas restreindre.
     *
     * La purge des secrets résiduels de l'outbox vivait ici : elle rescannait chaque jour toutes
     * les lignes `sent`, pour toujours et à coût croissant, alors qu'OutboxDrainer retire le jeton
     * au moment de l'envoi. C'était un rattrapage ponctuel déguisé en tâche périodique — il est
     * désormais joué une fois, en migration (2026_08_24_000010_purge_sent_outbox_secrets).
     */
    private function elaguer(): bool
    {
        return $this->callSilent('model:prune') === self::SUCCESS;
    }
}
