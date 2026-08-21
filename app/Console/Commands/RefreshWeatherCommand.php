<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\DuePeriodGuard;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// Pré-calcul périodique de la météo (PRD §4.13.5) : rafraîchit le cache Open-Meteo pour les
// séances géocodées de la fenêtre J-16. Déclenché par le cron unique (cadrage §7.13/§7.14).
class RefreshWeatherCommand extends Command
{
    protected $signature = 'weather:refresh
        {--if-due : N\'exécuter que si l\'échéance horaire courante n\'a pas déjà été honorée}';

    protected $description = 'Pré-calcule la météo Open-Meteo des séances géocodées dans la fenêtre J-16 (§4.13.5).';

    public function handle(WeatherService $weather, DuePeriodGuard $guard): int
    {
        // Mode rattrapable : la commande est planifiée toutes les 5 min et se garde elle-même,
        // car aucune minute d'horloge n'est fiable sur un cron horaire à minute imposée
        // (cf. RunCronLoopCommand). Sans --if-due, exécution inconditionnelle (appel manuel).
        if ($this->option('if-due')) {
            $guard->runIfDue(
                'weather-refresh',
                DuePeriodGuard::hourlyPeriod(),
                fn () => $this->rafraichir($weather),
            );

            return self::SUCCESS;
        }

        $this->rafraichir($weather);

        return self::SUCCESS;
    }

    /** Rafraîchit le cache météo. Retourne true si la passe s'est déroulée sans exception. */
    private function rafraichir(WeatherService $weather): bool
    {
        $sessions = Session::query()
            ->whereNull('cancelled_at')
            ->whereNotNull('location_id')
            ->where('start_at', '>', Carbon::now())
            ->where('start_at', '<=', Carbon::now()->addDays(WeatherService::WINDOW_DAYS))
            ->with('location')
            ->get()
            ->filter(fn (Session $s) => $s->location && $s->location->latitude !== null);

        $refreshed = 0;
        foreach ($sessions as $s) {
            $ok = $weather->forecast((float) $s->location->latitude, (float) $s->location->longitude, $s->start_at);
            if ($ok !== null) {
                $refreshed++;
            }
        }

        $this->info("Météo rafraîchie pour {$refreshed} séance(s) sur {$sessions->count()} géocodée(s).");

        return true;
    }
}
