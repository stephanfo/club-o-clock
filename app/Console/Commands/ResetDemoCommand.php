<?php

namespace App\Console\Commands;

use App\Services\DuePeriodGuard;
use App\Support\DemoMode;
use Database\Seeders\DemoSeeder;
use Database\Seeders\GpxRouteSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// Remise à zéro de l'instance de démonstration publique (plan open source OS7) : base
// reconstruite, jeu de démo TEAM44 rejoué, uploads purgés. Destinée au cron nocturne.
//
// ⚠️ Cette commande DÉTRUIT la base. Son unique garde-fou est `club.demo.enabled` : elle
// refuse de démarrer sur une instance qui n'est pas une démo. C'est volontairement le
// drapeau qui autorise, et non une confirmation interactive — un cron ne répond pas aux
// questions, et `--force` finirait par être tapé partout par habitude. Une instance de
// club n'a pas DEMO_MODE, donc la commande y est inerte, même lancée par erreur.
class ResetDemoCommand extends Command
{
    protected $signature = 'demo:reset
        {--if-due : N\'exécuter que si la remise à zéro du jour n\'a pas déjà eu lieu}';

    protected $description = 'Réinitialise l\'instance de démonstration (base, uploads, journaux)';

    /** Fuseau de référence de la démo, aligné sur routes/console.php (l'instance du projet). */
    private const FUSEAU = 'Europe/Paris';

    /** Répertoires d'uploads purgés à chaque remise à zéro, par disque. */
    private const UPLOADS = [
        'public' => ['logos'],
        'local' => ['gpx', 'livewire-tmp'],
    ];

    public function handle(DuePeriodGuard $guard): int
    {
        // Le garde d'échéance vient APRÈS le garde-fou DEMO_MODE : sur une instance de club, la
        // commande doit refuser, pas consommer silencieusement l'échéance du jour.
        if (DemoMode::enabled() && $this->option('if-due')) {
            $guard->runIfDue(
                'demo-reset',
                DuePeriodGuard::dailyPeriod(timezone: self::FUSEAU),
                fn () => $this->reinitialiser(),
            );

            return self::SUCCESS;
        }

        if (! DemoMode::enabled()) {
            $this->error('Refusé : cette instance n\'est pas une démo (DEMO_MODE absent du .env).');
            $this->line('  Cette commande détruit la base. Elle ne s\'exécute que sur une instance de démonstration.');

            return self::FAILURE;
        }

        $this->reinitialiser();

        return self::SUCCESS;
    }

    /** Effectue la remise à zéro. Retourne true si elle est allée au bout. */
    private function reinitialiser(): bool
    {
        $this->components->info('Réinitialisation de la démo…');

        // ⚠️ L'ORDRE COMPTE : on efface AVANT de reconstruire, jamais après.
        // GpxRouteSeeder écrit les 16 traces de démonstration dans `local:gpx`, le même
        // préfixe que les uploads. Purger ensuite les supprimait aussitôt, laissant en base
        // des parcours dont le gpx_path ne pointait plus sur rien — « Tracé indisponible »
        // sur toutes les fiches, alors que le seed venait de réussir.
        $purged = $this->purgeUploads();
        $this->components->task("uploads purgés ({$purged} fichiers)", fn () => true);

        $logs = $this->purgeLogs();
        $this->components->task("journaux purgés ({$logs} fichiers)", fn () => true);

        // --force : le cron tourne sans TTY, et APP_ENV vaut « production » sur l'hébergement
        // mutualisé même pour la démo — sans lui, migrate:fresh demanderait confirmation.
        $this->callSilent('migrate:fresh', ['--force' => true]);
        $this->components->task('base reconstruite', fn () => true);

        $this->callSilent('db:seed', ['--force' => true]);
        $this->components->task('réglages club et catalogues', fn () => true);

        $this->callSilent('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->components->task('jeu de démonstration', fn () => true);

        $this->callSilent('db:seed', ['--class' => GpxRouteSeeder::class, '--force' => true]);
        $this->components->task('parcours GPX', fn () => true);

        // Les caches portent des identifiants d'entités qui viennent de disparaître.
        $this->callSilent('optimize:clear');
        $this->components->task('caches vidés', fn () => true);

        $this->newLine();
        $this->components->info('Démo réinitialisée.');

        return true;
    }

    /**
     * Vide `storage/logs`.
     *
     * Une démo tourne avec `MAIL_MAILER=log` : le corps COMPLET de chaque email part dans le
     * journal, liens magiques et jetons d'invitation compris. Le fichier n'est pas atteignable
     * depuis le web (storage/ est hors racine), mais sans rotation il grossit indéfiniment sur
     * un hébergement à quota — et il survivrait à une remise à zéro qui efface tout le reste.
     * Purger ici garde le cycle cohérent : après un reset, l'instance ne conserve rien.
     */
    private function purgeLogs(): int
    {
        $logs = glob(storage_path('logs/*.log')) ?: [];

        foreach ($logs as $log) {
            @unlink($log);
        }

        return count($logs);
    }

    /** Vide les répertoires d'uploads sans supprimer les répertoires eux-mêmes. */
    private function purgeUploads(): int
    {
        $count = 0;

        foreach (self::UPLOADS as $disk => $directories) {
            $storage = Storage::disk($disk);

            foreach ($directories as $directory) {
                if (! $storage->exists($directory)) {
                    continue;
                }

                $count += count($storage->allFiles($directory));
                $storage->deleteDirectory($directory);
                $storage->makeDirectory($directory);
            }
        }

        return $count;
    }
}
