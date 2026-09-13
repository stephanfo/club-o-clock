<?php

namespace App\Services;

use App\Models\WeatherCacheEntry;
use App\Support\Weather;
use Carbon\CarbonInterface;
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

        $fresh = $this->fetchMany($lat, $lng, [$slot])[self::key($slot)] ?? null;
        if ($fresh === null) {
            return $entry?->forecast; // stale-while-error : on garde la dernière prévision connue.
        }

        WeatherCacheEntry::updateOrCreate(
            ['latitude' => $lat, 'longitude' => $lng, 'slot' => $slot],
            ['forecast' => $fresh, 'fetched_at' => Carbon::now()],
        );

        return $fresh;
    }

    /**
     * Prévision agrégée sur TOUTE la durée d'une séance (#55) — une séance longue n'a pas une
     * météo mais plusieurs : partir à 12 °C sous un ciel dégagé et finir à 19 °C sous l'averse.
     *
     * Ne coûte aucun appel réseau supplémentaire : `fetch()` reçoit déjà toutes les heures de la
     * fenêtre J-16 et n'en gardait qu'une. Une ligne de cache par heure, même clé, même TTL ; le
     * réseau n'est sollicité que si une heure manque ou a dépassé le TTL, et un seul appel alimente
     * alors toutes les heures.
     *
     * Dégradé gracieux inchangé : si l'appel échoue, on agrège sur les heures dont on dispose
     * (cache périmé compris) plutôt que de rendre null.
     *
     * @return array{tempStart:?float,tempEnd:?float,windMin:?float,windMax:?float,windDeg:?int,precipProb:?int,precipMm:?float,code:?int,hourStart:Carbon,hourEnd:Carbon}|null
     */
    public function forecastRange(float $lat, float $lng, Carbon $start, Carbon $end): ?array
    {
        if (! $this->inWindow($start)) {
            return null;
        }

        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $slots = self::slots($start, $end);

        $entries = WeatherCacheEntry::query()
            ->where('latitude', $lat)->where('longitude', $lng)
            ->whereIn('slot', array_map(fn (Carbon $s) => $s->format('Y-m-d H:i:s'), $slots))
            ->get()->keyBy(fn (WeatherCacheEntry $e) => self::key($e->slot));

        $limite = Carbon::now()->subHours(self::TTL_HOURS);
        $connues = [];
        $manque = false;
        foreach ($slots as $slot) {
            $entry = $entries[self::key($slot)] ?? null;
            if ($entry) {
                // Gardée même périmée : c'est la réserve du stale-while-error.
                $connues[self::key($slot)] = $entry->forecast;
            }
            if (! $entry || $entry->fetched_at->lessThanOrEqualTo($limite)) {
                $manque = true;
            }
        }

        if ($manque) {
            foreach ($this->fetchMany($lat, $lng, $slots) ?? [] as $cle => $prevision) {
                $connues[$cle] = $prevision;
                WeatherCacheEntry::updateOrCreate(
                    ['latitude' => $lat, 'longitude' => $lng, 'slot' => Carbon::createFromFormat('Y-m-d H', $cle)->startOfHour()],
                    ['forecast' => $prevision, 'fetched_at' => Carbon::now()],
                );
            }
        }

        // Remises dans l'ordre de la séance : l'agrégat lit la première et la dernière heure.
        $fenetre = [];
        foreach ($slots as $slot) {
            if (isset($connues[self::key($slot)])) {
                $fenetre[] = ['slot' => $slot, 'prevision' => $connues[self::key($slot)]];
            }
        }

        return $fenetre === [] ? null : self::agreger($fenetre);
    }

    /**
     * Heures pleines couvertes par [start, end], bornes incluses.
     *
     * Une fin tombant PILE sur l'heure pleine n'ouvre pas l'heure suivante : une séance 18:00 →
     * 19:00 tient dans la seule heure 18 (non-régression), là où 09:30 → 11:15 couvre 09, 10 et 11.
     *
     * @return array<int, Carbon>
     */
    private static function slots(Carbon $start, Carbon $end): array
    {
        $curseur = $start->copy()->setTime($start->hour, 0, 0);
        $derniere = $end->copy()->setTime($end->hour, 0, 0);
        if ($derniere->equalTo($end) && $derniere->greaterThan($curseur)) {
            $derniere->subHour();
        }

        $slots = [];
        while ($curseur->lessThanOrEqualTo($derniere)) {
            $slots[] = $curseur->copy();
            $curseur->addHour();
        }

        return $slots;
    }

    /**
     * Agrège les prévisions horaires d'une fenêtre, indexées par heure et dans l'ordre.
     *
     * Le service ne rend que des valeurs brutes : le seuil d'affichage de la plage de température
     * est une règle de présentation, elle vit dans la cartouche.
     *
     * @param  array<int, array{slot:Carbon, prevision:array<string, mixed>}>  $fenetre
     * @return array<string, mixed>
     */
    private static function agreger(array $fenetre): array
    {
        $previsions = array_column($fenetre, 'prevision');
        $premiere = $previsions[0];
        $derniere = $previsions[count($previsions) - 1];

        $vents = array_values(array_filter(array_map(fn ($p) => $p['wind'] ?? null, $previsions), fn ($v) => $v !== null));
        $probas = array_values(array_filter(array_map(fn ($p) => $p['precipProb'] ?? null, $previsions), fn ($v) => $v !== null));
        $mms = array_values(array_filter(array_map(fn ($p) => $p['precipMm'] ?? null, $previsions), fn ($v) => $v !== null));

        return [
            'tempStart' => self::flottant($premiere['temp'] ?? null),
            'tempEnd' => self::flottant($derniere['temp'] ?? null),
            'windMin' => $vents === [] ? null : self::flottant(min($vents)),
            'windMax' => $vents === [] ? null : self::flottant(max($vents)),
            'windDeg' => self::dominante($previsions),
            'precipProb' => $probas === [] ? null : (int) max($probas),
            'precipMm' => $mms === [] ? null : round(array_sum($mms), 1),
            'code' => Weather::worst(array_map(fn ($p) => isset($p['code']) ? (int) $p['code'] : null, $previsions)),
            // Instants UTC des heures extrêmes couvertes : c'est la VUE qui les passe en heure du
            // club, comme toute autre date de l'application.
            'hourStart' => $fenetre[0]['slot'],
            'hourEnd' => $fenetre[count($fenetre) - 1]['slot'],
        ];
    }

    /**
     * Direction dominante : le secteur cardinal le plus fréquent de la fenêtre, rendu par la
     * première direction qui y tombe — pour que la flèche de la cartouche corresponde à une heure
     * réelle plutôt qu'à une moyenne d'angles, qui n'a pas de sens autour du nord.
     *
     * @param  array<int, array<string, mixed>>  $previsions
     */
    private static function dominante(array $previsions): ?int
    {
        $degres = array_values(array_filter(array_map(fn ($p) => $p['windDeg'] ?? null, $previsions), fn ($v) => $v !== null));
        if ($degres === []) {
            return null;
        }

        $comptes = [];
        foreach ($degres as $deg) {
            $secteur = Weather::direction((int) $deg);
            $comptes[$secteur] = ($comptes[$secteur] ?? 0) + 1;
        }
        arsort($comptes);
        $dominant = array_key_first($comptes);

        foreach ($degres as $deg) {
            if (Weather::direction((int) $deg) === $dominant) {
                return (int) $deg;
            }
        }

        return (int) $degres[0];
    }

    private static function flottant(float|int|null $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    /**
     * Clé d'indexation d'un créneau horaire (heure pleine). Accepte n'importe quel Carbon : les
     * créneaux viennent tantôt du calcul (Illuminate), tantôt du cast Eloquent du modèle.
     */
    private static function key(CarbonInterface $slot): string
    {
        return $slot->format('Y-m-d H');
    }

    /** Le créneau est-il dans la fenêtre [maintenant, J-16] ? */
    public function inWindow(Carbon $slot): bool
    {
        return $slot->isFuture() && $slot->lessThanOrEqualTo(Carbon::now()->addDays(self::WINDOW_DAYS));
    }

    /**
     * Appel Open-Meteo (borné à 4 s, jamais d'exception remontée) : UN seul appel quel que soit le
     * nombre de créneaux demandés — la réponse porte déjà toutes les heures de la fenêtre J-16.
     *
     * @param  array<int, Carbon>  $slots
     * @return array<string, array<string, mixed>>|null prévisions indexées par créneau
     */
    private function fetchMany(float $lat, float $lng, array $slots): ?array
    {
        try {
            $res = Http::timeout(4)->get(self::ENDPOINT, [
                'latitude' => $lat,
                'longitude' => $lng,
                'hourly' => 'temperature_2m,precipitation_probability,precipitation,wind_speed_10m,wind_direction_10m,weather_code',
                'forecast_days' => self::WINDOW_DAYS,
                // UTC et non `auto` : `auto` renvoie les libellés en heure locale du LIEU, alors
                // que les créneaux sont des Carbon UTC (config app.timezone). L'index tombait donc
                // deux heures trop tôt l'été. L'heure du club n'intervient qu'à l'affichage.
                'timezone' => 'UTC',
            ]);

            if (! $res->ok()) {
                return null;
            }

            $h = $res->json('hourly');
            if (! is_array($h) || empty($h['time'])) {
                return null;
            }

            $out = [];
            foreach ($slots as $slot) {
                $i = array_search($slot->format('Y-m-d\TH:00'), $h['time'], true);
                if ($i === false) {
                    continue; // Heure absente de la réponse : on agrégera sur les autres.
                }
                $out[self::key($slot)] = [
                    'temp' => self::at($h, 'temperature_2m', $i),
                    'precipProb' => self::at($h, 'precipitation_probability', $i),
                    'precipMm' => self::at($h, 'precipitation', $i),
                    'wind' => self::at($h, 'wind_speed_10m', $i),
                    'windDeg' => self::at($h, 'wind_direction_10m', $i),
                    'code' => self::at($h, 'weather_code', $i),
                ];
            }

            return $out === [] ? null : $out;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function at(array $h, string $key, int $i): float|int|null
    {
        return $h[$key][$i] ?? null;
    }
}
