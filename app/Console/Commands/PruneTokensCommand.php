<?php

namespace App\Console\Commands;

use App\Models\NotificationOutbox;
use App\Services\DuePeriodGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

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

    protected $description = 'Élague les jetons d\'authentification expirés et purge les secrets résiduels des envois effectués.';

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
        $ok = $this->callSilent('model:prune') === self::SUCCESS;

        $this->purgerSecretsEnvoyes();

        return $ok;
    }

    /**
     * Purge les secrets restés dans le payload d'envois DÉJÀ EFFECTUÉS.
     *
     * OutboxDrainer les retire désormais au moment de l'envoi ; ceci rattrape les lignes écrites
     * avant, qui gardaient un jeton d'invitation en clair — de quoi entrer dans le compte visé.
     * Bornée aux lignes `sent` : une ligne `failed` reste rejouable, la vider produirait un lien
     * mort. Chaque ligne est réécrite individuellement (payload JSON : pas d'UPDATE ensembliste
     * portable entre MySQL et MariaDB pour retirer une clé).
     */
    private function purgerSecretsEnvoyes(): void
    {
        NotificationOutbox::query()
            ->where('status', 'sent')
            ->whereNotNull('payload')
            ->chunkById(200, function ($lignes) {
                foreach ($lignes as $ligne) {
                    $propre = Arr::except($ligne->payload ?? [], NotificationOutbox::SENSITIVE_PAYLOAD_KEYS);

                    if ($propre !== ($ligne->payload ?? [])) {
                        $ligne->update(['payload' => $propre]);
                    }
                }
            });
    }
}
