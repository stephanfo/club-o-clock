<?php

namespace App\Support;

use App\Models\GpxRoute;

/**
 * Garde serveur des données GPX (PRD §4.20, cadrage §7.6).
 *
 * Le fichier GPX n'est JAMAIS parsé côté serveur (surface XXE) : toute l'extraction est faite par
 * resources/js/gpx.js dans le navigateur. Les valeurs qui arrivent ici sont donc, par construction,
 * fournies par le client et non fiables. Cette classe est le point de passage unique qui les borne
 * avant stockage.
 *
 * Deux régimes, volontairement asymétriques :
 *   - métriques (sanitize)  → CLAMP. Une distance aberrante ramenée à une borne est sans conséquence.
 *   - géographie (sanitizeGeo) → REJET. Clamper une latitude à 90 placerait le parcours au pôle Nord
 *     et polluerait la carte et la recherche par zone : on annule tout le bloc géo.
 */
class GpxStats
{
    /** Taille maximale d'un GPX (§4.13.2). */
    public const MAX_KB = 5120;

    /** Plafond dur de la polyline simplifiée stockée (garde-fou anti-payload hostile). */
    public const MAX_POLYLINE_POINTS = 250;

    /** Plafond du profil altimétrique stocké. */
    public const MAX_PROFILE_POINTS = 200;

    /** Seuil de bouclage : départ et arrivée à moins de 250 m (§1). */
    public const LOOP_METERS = 250;

    /**
     * Règles de validation du fichier uploadé, partagées par SessionForm et GpxRouteForm.
     * Source unique : dupliquer la limite garantirait qu'un formulaire diverge de l'autre.
     *
     * @return array<int, mixed>
     */
    public static function fileRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:'.self::MAX_KB,
            function (string $attr, mixed $value, callable $fail): void {
                if ($value && strtolower($value->getClientOriginalExtension()) !== 'gpx') {
                    $fail('Le fichier doit être un .gpx.');
                }
            },
        ];
    }

    /**
     * Borne les métriques d'affichage (déplacé depuis SessionForm::sanitizeGpxStats).
     *
     * @param  array<string, mixed>|null  $s
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $s): ?array
    {
        if (! $s) {
            return null;
        }

        $num = fn ($v, $min, $max) => is_numeric($v) ? max($min, min($max, round((float) $v, 1))) : null;

        return array_filter([
            'name' => isset($s['name']) ? mb_substr((string) $s['name'], 0, 255) : null,
            'sizeKo' => $num($s['sizeKo'] ?? null, 0, self::MAX_KB),
            'distanceKm' => $num($s['distanceKm'] ?? null, 0, 100000),
            'dplus' => $num($s['dplus'] ?? null, 0, 100000),
            'dmoins' => $num($s['dmoins'] ?? null, 0, 100000),
            'altMin' => $num($s['altMin'] ?? null, -1000, 10000),
            'altMax' => $num($s['altMax'] ?? null, -1000, 10000),
            'pointCount' => $num($s['pointCount'] ?? null, 0, 10000000),
            'durationMin' => $num($s['durationMin'] ?? null, 0, 100000),
        ], fn ($v) => $v !== null);
    }

    /**
     * Valide le bloc géographique. REJET EN BLOC : si une seule valeur est incohérente, on renvoie
     * null et le parcours est créé sans géo (il reste consultable et téléchargeable, il n'apparaît
     * simplement ni sur la carte ni dans les filtres de direction).
     *
     * @param  array<string, mixed>|null  $geo
     * @return array<string, mixed>|null
     */
    public static function sanitizeGeo(?array $geo): ?array
    {
        if (! $geo) {
            return null;
        }

        $start = self::coords($geo['start'] ?? null);
        $end = self::coords($geo['end'] ?? null);
        $bbox = $geo['bbox'] ?? null;

        if ($start === null || ! is_array($bbox)) {
            return null;
        }

        $minLat = self::lat($bbox['minLat'] ?? null);
        $maxLat = self::lat($bbox['maxLat'] ?? null);
        $minLng = self::lng($bbox['minLon'] ?? $bbox['minLng'] ?? null);
        $maxLng = self::lng($bbox['maxLon'] ?? $bbox['maxLng'] ?? null);

        if ($minLat === null || $maxLat === null || $minLng === null || $maxLng === null) {
            return null;
        }

        // Bbox inversée → données incohérentes, on ne garde rien.
        if ($minLat > $maxLat || $minLng > $maxLng) {
            return null;
        }

        // Le départ doit se trouver dans l'emprise : sinon les deux ne décrivent pas la même trace.
        if ($start['lat'] < $minLat || $start['lat'] > $maxLat || $start['lng'] < $minLng || $start['lng'] > $maxLng) {
            return null;
        }

        // Cap et secteur sont recalculés serveur : la valeur client n'est qu'une indication.
        // Référence = centroïde de la bbox (stable quels que soient le point de départ et le sens,
        // contrairement au point le plus éloigné, arbitraire sur une boucle).
        $bearing = self::bearing($start['lat'], $start['lng'], ($minLat + $maxLat) / 2, ($minLng + $maxLng) / 2);

        // isLoop recalculé plutôt que cru sur parole.
        $isLoop = $end !== null
            && self::haversine($start['lat'], $start['lng'], $end['lat'], $end['lng']) < self::LOOP_METERS;

        return [
            'start_lat' => $start['lat'],
            'start_lng' => $start['lng'],
            'end_lat' => $end['lat'] ?? null,
            'end_lng' => $end['lng'] ?? null,
            'is_loop' => $isLoop,
            'elongation' => self::elongation($minLat, $minLng, $maxLat, $maxLng),
            'bbox_min_lat' => $minLat,
            'bbox_min_lng' => $minLng,
            'bbox_max_lat' => $maxLat,
            'bbox_max_lng' => $maxLng,
            'bearing_deg' => $bearing,
            'sector' => self::sectorFromBearing($bearing),
        ];
    }

    /**
     * Allongement de l'emprise : côté long / côté court, en mètres réels (la longitude est corrigée
     * par cos(lat), sinon un circuit est-ouest paraîtrait plus étiré qu'il ne l'est).
     * Recalculé serveur depuis la bbox déjà validée — rien à croire du client.
     */
    private static function elongation(float $minLat, float $minLng, float $maxLat, float $maxLng): ?float
    {
        $heightM = ($maxLat - $minLat) * 111320;
        $widthM = ($maxLng - $minLng) * 111320 * cos(deg2rad(($minLat + $maxLat) / 2));

        $long = max($heightM, $widthM);
        $short = min($heightM, $widthM);

        // Emprise dégénérée (trace quasi rectiligne ou ponctuelle) : pas de forme exploitable.
        if ($short < 1 || $long <= 0) {
            return null;
        }

        return min(99.99, round($long / $short, 2));
    }

    /** Secteur cardinal depuis un cap en degrés. 8 secteurs de 45°, centrés (N = 337.5°..22.5°). */
    public static function sectorFromBearing(?int $bearing): ?string
    {
        if ($bearing === null) {
            return null;
        }

        return GpxRoute::SECTORS[(int) round($bearing / 45) % 8];
    }

    /**
     * Polyline simplifiée : tronquée au plafond dur, arrondie à 5 décimales (~1 m), < 2 points → null.
     *
     * @param  mixed  $polyline
     * @return array<int, array{0: float, 1: float}>|null
     */
    public static function sanitizePolyline($polyline): ?array
    {
        if (! is_array($polyline)) {
            return null;
        }

        $out = [];
        foreach (array_slice($polyline, 0, self::MAX_POLYLINE_POINTS) as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $lat = self::lat($pair[0] ?? null);
            $lng = self::lng($pair[1] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }
            $out[] = [round($lat, 5), round($lng, 5)];
        }

        return count($out) >= 2 ? $out : null;
    }

    /**
     * Profil altimétrique [[distKm, altM], …], tronqué et borné.
     *
     * @param  mixed  $profile
     * @return array<int, array{0: float, 1: float}>|null
     */
    public static function sanitizeElevationProfile($profile): ?array
    {
        if (! is_array($profile)) {
            return null;
        }

        $out = [];
        foreach (array_slice($profile, 0, self::MAX_PROFILE_POINTS) as $pair) {
            if (! is_array($pair) || count($pair) < 2 || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
                continue;
            }
            $out[] = [
                round(max(0, min(100000, (float) $pair[0])), 3),
                round(max(-1000, min(10000, (float) $pair[1])), 1),
            ];
        }

        return count($out) >= 2 ? $out : null;
    }

    /**
     * @param  mixed  $p
     * @return array{lat: float, lng: float}|null
     */
    private static function coords($p): ?array
    {
        if (! is_array($p)) {
            return null;
        }
        $lat = self::lat($p['lat'] ?? null);
        $lng = self::lng($p['lon'] ?? $p['lng'] ?? null);

        return $lat === null || $lng === null ? null : ['lat' => $lat, 'lng' => $lng];
    }

    private static function lat(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v >= -90 && (float) $v <= 90 ? (float) $v : null;
    }

    private static function lng(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v >= -180 && (float) $v <= 180 ? (float) $v : null;
    }

    /** Cap great-circle initial de A vers B, en degrés 0..359. */
    private static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLambda = deg2rad($lng2 - $lng1);

        $y = sin($dLambda) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLambda);

        return ((int) round(rad2deg(atan2($y, $x))) % 360 + 360) % 360;
    }

    /** Distance entre deux points, en mètres. */
    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLambda / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
