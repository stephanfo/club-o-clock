<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Géocodage d'adresse via Nominatim (OSM) — service ouvert, sans clé, UE (PRD §4.13.4).
// Politique d'usage Nominatim : User-Agent identifiable + 1 req/s. On met en cache les résultats
// (30 j) pour ne pas re-géocoder une même adresse. Échec → null (saisie manuelle lat/lng côté UI).
class GeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        return Cache::remember('geocode:'.md5(mb_strtolower($address)), now()->addDays(30), function () use ($address) {
            try {
                $res = Http::withHeaders([
                    // Repli sur le nom du LOGICIEL si APP_NAME manque : c'est l'identité envoyée
                    // à Nominatim, dont la politique d'usage exige un agent identifiable.
                    'User-Agent' => config('app.name', "Club'O'Clock").' (auto-hébergé)',
                ])->timeout(8)->get(self::ENDPOINT, [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'addressdetails' => 0,
                ]);

                if (! $res->ok()) {
                    return null;
                }

                $first = $res->json('0');
                if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
                    return null;
                }

                return ['lat' => round((float) $first['lat'], 7), 'lng' => round((float) $first['lon'], 7)];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Suggestions d'adresses (autocomplétion §4.13.4) : plusieurs résultats Nominatim pour une
     * saisie partielle (adresse OU nom de lieu/POI). Affichage type carte : un nom court en titre,
     * l'adresse formatée en dessous, et le type lisible — au lieu du `display_name` concaténé brut.
     * Cache court (6 h) car la frappe varie à chaque caractère ; on saute les requêtes < 4 caractères
     * pour ne pas marteler Nominatim (politique 1 req/s). Échec → [].
     *
     * @return list<array{name: string, address: string, type: ?string, lat: float, lng: float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 4) {
            return [];
        }

        return Cache::remember('geosearch:'.md5(mb_strtolower($query)), now()->addHours(6), function () use ($query, $limit) {
            try {
                $res = Http::withHeaders([
                    // Repli sur le nom du LOGICIEL si APP_NAME manque : c'est l'identité envoyée
                    // à Nominatim, dont la politique d'usage exige un agent identifiable.
                    'User-Agent' => config('app.name', "Club'O'Clock").' (auto-hébergé)',
                ])->timeout(8)->get(self::ENDPOINT, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => $limit,
                    'addressdetails' => 1,
                    'accept-language' => 'fr',
                ]);

                if (! $res->ok()) {
                    return [];
                }

                return collect($res->json())
                    ->filter(fn ($r) => is_array($r) && isset($r['lat'], $r['lon'], $r['display_name']))
                    ->map(fn ($r) => [
                        'name' => $this->extractName($r),
                        'address' => $this->extractAddress($r),
                        'type' => $this->extractType($r),
                        'lat' => round((float) $r['lat'], 7),
                        'lng' => round((float) $r['lon'], 7),
                    ])
                    ->values()
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** Nom court du résultat : le nom du POI si présent, sinon la 1re composante du display_name. */
    private function extractName(array $r): string
    {
        $name = trim((string) ($r['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim(explode(',', (string) $r['display_name'])[0]);
    }

    /**
     * Adresse lisible reconstruite depuis le bloc `address` structuré (numéro + rue, code postal +
     * ville, pays) — évite le `display_name` brut qui répète le nom et empile quartier/département/etc.
     */
    private function extractAddress(array $r): string
    {
        $a = $r['address'] ?? [];
        if (! is_array($a)) {
            return (string) $r['display_name'];
        }

        $street = trim(($a['house_number'] ?? '').' '.($a['road'] ?? ''));
        $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? null;
        $cityLine = trim(($a['postcode'] ?? '').' '.($city ?? ''));

        $parts = array_filter([$street, $cityLine, $a['country'] ?? null], fn ($p) => trim((string) $p) !== '');

        // Si le bloc structuré n'a rien donné (résultat sans `address`), on retombe sur le display_name.
        return $parts !== [] ? implode(', ', $parts) : (string) $r['display_name'];
    }

    /** Type lisible (FR) du lieu, déduit de `addresstype`/`type` Nominatim. Null si non pertinent. */
    private function extractType(array $r): ?string
    {
        $key = $r['addresstype'] ?? $r['type'] ?? null;
        if (! is_string($key)) {
            return null;
        }

        return self::TYPE_LABELS[$key] ?? null;
    }

    /** Quelques types Nominatim utiles pour un club (sport / lieux publics) → libellé FR. */
    private const TYPE_LABELS = [
        'swimming_pool' => 'Piscine',
        'sports_centre' => 'Centre sportif',
        'stadium' => 'Stade',
        'pitch' => 'Terrain',
        'track' => 'Piste',
        'sports_hall' => 'Gymnase',
        'leisure' => 'Loisirs',
        'park' => 'Parc',
        'school' => 'École',
        'college' => 'Établissement scolaire',
        'university' => 'Université',
        'parking' => 'Parking',
    ];
}
