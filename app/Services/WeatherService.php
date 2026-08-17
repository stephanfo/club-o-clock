<?php

namespace App\Services;

use App\Models\WeatherCacheEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Météo prévisionnelle Open-Meteo (PRD §4.13.5) — gratuit, sans clé, UE, CC BY 4.0.
// Cache serveur 3 h par (lieu, créneau) ; fenêtre J-16. Paramètres : température 2 m, proba et
// quantité de précip., vent (vitesse + direction), code météo. Échec → dégradé gracieux (cache périmé
// servi si présent, sinon null). Aucun appel sortant n'interrompt le rendu de la fiche.
class WeatherService
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    private const TTL_HOURS = 3;

    public const WINDOW_DAYS = 16;

    /**
     * Prévision pour (lat, lng) au créneau $slot. Sert le cache s'il est frais (< 3 h), sinon
     * tente un rafraîchissement et met à jour le cache. Hors fenêtre J-16 → null.
     *
     * @return array{temp:?float,precipProb:?int,precipMm:?float,wind:?float,windDeg:?int,code:?int}|null
     */
    public function forecast(float $lat, float $lng, Carbon $slot): ?array
    {
        if (! $this->inWindow($slot)) {
            return null;
        }

        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $slot = $slot->copy()->setTime($slot->hour, 0, 0);

        $entry = WeatherCacheEntry::query()
            ->where('latitude', $lat)->where('longitude', $lng)->where('slot', $slot)
            ->first();

        if ($entry && $entry->fetched_at->greaterThan(Carbon::now()->subHours(self::TTL_HOURS))) {
            return $entry->forecast;
        }

        $fresh = $this->fetch($lat, $lng, $slot);
        if ($fresh === null) {
            return $entry?->forecast; // stale-while-error : on garde la dernière prévision connue.
        }

        WeatherCacheEntry::updateOrCreate(
            ['latitude' => $lat, 'longitude' => $lng, 'slot' => $slot],
            ['forecast' => $fresh, 'fetched_at' => Carbon::now()],
        );

        return $fresh;
    }

    /** Le créneau est-il dans la fenêtre [maintenant, J-16] ? */
    public function inWindow(Carbon $slot): bool
    {
        return $slot->isFuture() && $slot->lessThanOrEqualTo(Carbon::now()->addDays(self::WINDOW_DAYS));
    }

    /** Appel Open-Meteo (borné à 4 s, jamais d'exception remontée). */
    private function fetch(float $lat, float $lng, Carbon $slot): ?array
    {
        try {
            $res = Http::timeout(4)->get(self::ENDPOINT, [
                'latitude' => $lat,
                'longitude' => $lng,
                'hourly' => 'temperature_2m,precipitation_probability,precipitation,wind_speed_10m,wind_direction_10m,weather_code',
                'forecast_days' => self::WINDOW_DAYS,
                'timezone' => 'auto',
            ]);

            if (! $res->ok()) {
                return null;
            }

            $h = $res->json('hourly');
            if (! is_array($h) || empty($h['time'])) {
                return null;
            }

            $i = array_search($slot->format('Y-m-d\TH:00'), $h['time'], true);
            if ($i === false) {
                return null;
            }

            return [
                'temp' => self::at($h, 'temperature_2m', $i),
                'precipProb' => self::at($h, 'precipitation_probability', $i),
                'precipMm' => self::at($h, 'precipitation', $i),
                'wind' => self::at($h, 'wind_speed_10m', $i),
                'windDeg' => self::at($h, 'wind_direction_10m', $i),
                'code' => self::at($h, 'weather_code', $i),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function at(array $h, string $key, int $i): float|int|null
    {
        return $h[$key][$i] ?? null;
    }
}
