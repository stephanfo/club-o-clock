<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// Pré-calcul périodique de la météo (PRD §4.13.5) : rafraîchit le cache Open-Meteo pour les
// séances géocodées de la fenêtre J-16. Déclenché par le cron unique (cadrage §7.13/§7.14).
class RefreshWeatherCommand extends Command
{
    protected $signature = 'weather:refresh';

    protected $description = 'Pré-calcule la météo Open-Meteo des séances géocodées dans la fenêtre J-16 (§4.13.5).';

    public function handle(WeatherService $weather): int
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

        return self::SUCCESS;
    }
}
