<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Géocodage d'adresse via Photon (OSM) — service ouvert, sans clé, hébergé en UE (PRD §4.13.4).
 *
 * Photon sert la MÊME donnée OpenStreetMap que Nominatim, qu'il remplace (#63), mais par un moteur
 * conçu pour la recherche incrémentale : il dégrade en douceur là où Nominatim appariait l'adresse
 * complète — et rendait, sur une saisie partielle, soit rien, soit une commune voisine plausible
 * mais fausse. Résultats mis en cache pour ne pas re-géocoder une même saisie. Échec → null / []
 * (la saisie manuelle lat/lng reste le repli côté UI).
 */
class GeocodingService
{
    private const ENDPOINT = 'https://photon.komoot.io/api';

    /**
     * Mémo du barycentre du catalogue, pour ne pas le recalculer à chaque frappe. `false` = pas
     * encore calculé, distinct de `null` = calculé, et il n'y a pas de biais.
     *
     * @var array{lat: float, lng: float}|null|false
     */
    private array|null|false $biais = false;

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        return Cache::remember('geocode:'.$this->empreinte().':'.md5(mb_strtolower($address)), now()->addDays(30), function () use ($address) {
            $premier = $this->interroger($address, 1)[0] ?? null;

            return $premier === null ? null : ['lat' => $premier['lat'], 'lng' => $premier['lng']];
        });
    }

    /**
     * Suggestions d'adresses (autocomplétion §4.13.4) : plusieurs résultats pour une saisie
     * partielle (adresse OU nom de lieu/POI). Affichage type carte : un nom court en titre,
     * l'adresse formatée en dessous, et le type lisible. Cache court (6 h) car la frappe varie à
     * chaque caractère ; on saute les requêtes < 4 caractères pour ne pas marteler le service.
     * Échec → [].
     *
     * @return list<array{name: string, address: string, type: ?string, lat: float, lng: float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 4) {
            return [];
        }

        return Cache::remember('geosearch:'.$this->empreinte().':'.md5(mb_strtolower($query)), now()->addHours(6), fn () => $this->interroger($query, $limit));
    }

    /**
     * Un appel à Photon, traduit vers la forme commune, dédoublonné, ramené à `$limit`.
     *
     * On demande **le double** de résultats : les doublons OSM (cf. dedoublonner()) mangeraient
     * sinon des places dans une liste qui n'en compte que cinq.
     *
     * @return list<array{name: string, address: string, type: ?string, lat: float, lng: float}>
     */
    private function interroger(string $query, int $limit): array
    {
        try {
            $params = ['q' => $query, 'limit' => $limit * 2, 'lang' => 'fr'];
            if ($biais = $this->biais()) {
                $params['lat'] = $biais['lat'];
                $params['lon'] = $biais['lng'];
            }

            $res = Http::withHeaders([
                // Repli sur le nom du LOGICIEL si APP_NAME manque : c'est l'identité envoyée au
                // service, dont la politique d'usage demande un agent identifiable.
                'User-Agent' => config('app.name', "Club'O'Clock").' (auto-hébergé)',
            ])->timeout(8)->get(self::ENDPOINT, $params);

            if (! $res->ok()) {
                return [];
            }

            $lus = collect($res->json('features') ?? [])
                ->map(fn ($f) => $this->mapFeature(is_array($f) ? $f : []))
                ->filter()
                ->all();

            return array_slice($this->dedoublonner(array_values($lus)), 0, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Traduit un `feature` GeoJSON de Photon vers la forme commune. Null si inexploitable.
     *
     * ⚠️ PIÈGE : `geometry.coordinates` est `[longitude, latitude]`, l'inverse de l'ordre employé
     * partout ailleurs dans ce dépôt. Intervertir les deux pose le marqueur dans l'océan sans
     * qu'aucune assertion de forme ne s'en aperçoive — d'où un test dédié (GeocodingTest).
     *
     * @param  array<string, mixed>  $f
     * @return array{name: string, address: string, type: ?string, lat: float, lng: float}|null
     */
    private function mapFeature(array $f): ?array
    {
        $coords = $f['geometry']['coordinates'] ?? null;
        $p = $f['properties'] ?? null;
        if (! is_array($coords) || ! isset($coords[0], $coords[1]) || ! is_array($p)) {
            return null;
        }

        // Photon rend l'adresse déjà décomposée : pas de `display_name` concaténé à démonter.
        $voie = trim(((string) ($p['housenumber'] ?? '')).' '.((string) ($p['street'] ?? '')));
        $commune = trim(((string) ($p['postcode'] ?? '')).' '.((string) ($p['city'] ?? '')));
        $parts = array_filter([$voie, $commune, $p['country'] ?? null], fn ($x) => trim((string) $x) !== '');

        // Titre : le nom du POI, sinon la voie, sinon la commune — jamais le numéro seul, qui
        // s'affichait sous le titre « 5 » du temps de Nominatim.
        $nom = trim((string) ($p['name'] ?? '')) ?: trim((string) ($p['street'] ?? '')) ?: trim((string) ($p['city'] ?? ''));

        // `osm_value` porte les mêmes valeurs qu'`addresstype` chez Nominatim (swimming_pool,
        // sports_centre…) : la table TYPE_LABELS se réutilise sans la retoucher.
        $cle = $p['osm_value'] ?? null;

        return [
            'name' => $nom !== '' ? $nom : '?',
            'address' => implode(', ', $parts),
            'type' => is_string($cle) ? (self::TYPE_LABELS[$cle] ?? null) : null,
            'lat' => round((float) $coords[1], 7),
            'lng' => round((float) $coords[0], 7),
        ];
    }

    /**
     * Un lieu, une suggestion. OSM porte parfois plusieurs objets pour un même endroit, du même
     * nom, à quelques dizaines de mètres : « Complexe sportif du Buisson de la Grolle » remonte
     * ainsi deux fois — le centre sportif, et le défibrillateur posé devant. Même nom + même
     * position à ~100 m près ⇒ même lieu.
     *
     * Entre deux doublons, c'est le typé qui reste : un « Centre sportif » se lit à l'écran, un
     * nœud sans type lisible non.
     *
     * @param  list<array{name: string, address: string, type: ?string, lat: float, lng: float}>  $res
     * @return list<array{name: string, address: string, type: ?string, lat: float, lng: float}>
     */
    private function dedoublonner(array $res): array
    {
        $vus = [];
        foreach ($res as $r) {
            $cle = mb_strtolower($r['name']).'@'.round($r['lat'], 3).','.round($r['lng'], 3);
            if (! isset($vus[$cle]) || ($vus[$cle]['type'] === null && $r['type'] !== null)) {
                $vus[$cle] = $r;
            }
        }

        return array_values($vus);
    }

    /**
     * Biais géographique : Photon pousse les résultats proches de ce point. Il **classe**, il ne
     * filtre pas — une compétition à l'autre bout du pays reste trouvable.
     *
     * Le point de référence est le barycentre des lieux favoris géocodés : aucun réglage à saisir,
     * et il s'adapte de lui-même au club qui déploie son instance — cohérent avec « chacun son
     * instance ». Les lieux archivés n'y entrent pas : ils ne disent plus où le club s'entraîne.
     * Catalogue vide ⇒ pas de biais, dégradation propre.
     *
     * Arrondi au centième de degré (~1 km) : au-delà, la précision ne change rien au classement,
     * et une clé de cache stable évite de tout invalider au moindre lieu ajouté.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function biais(): ?array
    {
        if ($this->biais !== false) {
            return $this->biais;
        }

        $q = Location::query()
            ->where('is_archived', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        $lat = $q->avg('latitude');

        return $this->biais = $lat === null
            ? null
            : ['lat' => round((float) $lat, 2), 'lng' => round((float) (clone $q)->avg('longitude'), 2)];
    }

    /** Empreinte du biais, injectée dans toute clé de cache : sans elle, le premier lieu ajouté au
     *  catalogue d'un club ne changerait rien aux suggestions pendant six heures. */
    private function empreinte(): string
    {
        $b = $this->biais();

        return $b === null ? 'sans-biais' : $b['lat'].','.$b['lng'];
    }

    /** Quelques types OSM utiles pour un club (sport / lieux publics) → libellé FR. */
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
