<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Propriétés du géocodeur Photon (#63) — celles que la forme des données ne garantit pas.
 *
 * Les cas nominaux (cartographie d'un résultat, cache, panne) vivent dans WeatherGeocodingTest,
 * avec la météo qu'ils alimentent. Ici, les trois pièges du moteur : l'ordre des coordonnées, les
 * doublons OSM, et le biais géographique déduit du catalogue.
 */
class GeocodingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * LE piège du moteur : Photon rend `geometry.coordinates` en `[longitude, latitude]`, l'inverse
     * de l'ordre employé partout ailleurs dans ce dépôt. Interverti, le marqueur part en mer sans
     * qu'aucune assertion de forme ne bronche — d'où deux valeurs volontairement dissemblables,
     * et de signes opposés.
     */
    public function test_les_coordonnees_ne_sont_pas_interverties(): void
    {
        $this->photonFake([$this->feature(-1.17, 47.37)]);

        $res = app(GeocodingService::class)->search('piscine ancenis');

        $this->assertSame(47.37, $res[0]['lat']);
        $this->assertSame(-1.17, $res[0]['lng']);
    }

    /**
     * Cas réel observé à l'essai : « Complexe sportif du Buisson de la Grolle » remonte deux fois —
     * le centre sportif, et le défibrillateur posé devant, qui porte le même nom à 30 m. Deux objets
     * OSM, un seul lieu : la liste de suggestions ne doit en proposer qu'un.
     *
     * C'est le typé qui est retenu : entre « Centre sportif » et un nœud sans type lisible, c'est
     * celui qui se laisse nommer à l'écran qui sert.
     */
    public function test_les_doublons_osm_dun_meme_lieu_sont_fusionnes(): void
    {
        $this->photonFake([
            $this->feature(-1.537896, 47.28902, 'Complexe sportif du Buisson de la Grolle', 'defibrillator'),
            $this->feature(-1.538176, 47.289272, 'Complexe sportif du Buisson de la Grolle', 'sports_centre'),
        ]);

        $res = app(GeocodingService::class)->search('buisson de la grolle');

        $this->assertCount(1, $res);
        $this->assertSame('Centre sportif', $res[0]['type']);
    }

    /** Contrôle positif de la fusion : deux lieux voisins mais distincts restent deux suggestions. */
    public function test_deux_lieux_voisins_de_noms_differents_sont_conserves(): void
    {
        $this->photonFake([
            $this->feature(-1.538, 47.289, 'Gymnase du Buisson', 'sports_hall'),
            $this->feature(-1.5381, 47.2891, 'Stade du Buisson', 'stadium'),
        ]);

        $res = app(GeocodingService::class)->search('buisson');

        $this->assertCount(2, $res);
    }

    /**
     * Le biais pousse les résultats proches du club sans jamais filtrer : c'est un classement, pas
     * une restriction. Son point de référence est le barycentre des lieux favoris géocodés — aucun
     * réglage à saisir, et il s'adapte de lui-même au club qui déploie son instance.
     *
     * Les lieux archivés et ceux sans coordonnées n'y entrent pas : le premier ne décrit plus où le
     * club s'entraîne, le second n'a rien à y apporter.
     */
    public function test_le_biais_est_le_barycentre_des_lieux_favoris_geocodes(): void
    {
        $this->photonFake([$this->feature(-1.17, 47.37)]);
        $admin = User::factory()->admin()->create();
        Location::create(['name' => 'Piscine', 'latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $admin->id]);
        Location::create(['name' => 'Stade', 'latitude' => 47.21, 'longitude' => -1.55, 'created_by' => $admin->id]);
        Location::create(['name' => 'Ancien gymnase', 'latitude' => 43.6, 'longitude' => 1.44, 'is_archived' => true, 'created_by' => $admin->id]);
        Location::create(['name' => 'Sans coordonnées', 'address' => 'quelque part', 'created_by' => $admin->id]);

        app(GeocodingService::class)->search('piscine ancenis');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'lat=47.29') && str_contains($r->url(), 'lon=-1.36'));
    }

    /** Catalogue vide (ou aucun lieu géocodé) : aucun biais — dégradation propre, pas un point arbitraire. */
    public function test_sans_lieu_geocode_aucun_biais_nest_envoye(): void
    {
        $this->photonFake([$this->feature(-1.17, 47.37)]);

        app(GeocodingService::class)->search('piscine ancenis');

        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'lat=') && ! str_contains($r->url(), 'lon='));
    }

    /**
     * Le barycentre entre dans la clé de cache. Sans ça, le premier lieu ajouté au catalogue d'un
     * nouveau club ne changerait rien aux suggestions pendant 6 h — et le biais paraîtrait inopérant.
     */
    public function test_le_cache_des_suggestions_suit_le_barycentre(): void
    {
        $this->photonFake([$this->feature(-1.17, 47.37)]);
        $admin = User::factory()->admin()->create();

        app(GeocodingService::class)->search('piscine ancenis');
        app(GeocodingService::class)->search('piscine ancenis');
        Http::assertSentCount(1);

        Location::create(['name' => 'Piscine', 'latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $admin->id]);
        app(GeocodingService::class)->search('piscine ancenis');
        Http::assertSentCount(2);
    }

    /**
     * Sur une voie, Photon ne rend pas de `name` : le titre retombe sur la rue, puis sur la commune.
     * Il ne retombe jamais sur le numéro de voie — c'est le travers qu'avait Nominatim, où
     * « 5 rue du Stade » s'affichait sous le titre « 5 ».
     */
    public function test_le_titre_dune_voie_est_la_rue_jamais_le_numero(): void
    {
        $this->photonFake([[
            'geometry' => ['coordinates' => [-1.17, 47.37]],
            'properties' => ['housenumber' => '5', 'street' => 'Rue du Stade', 'postcode' => '44150', 'city' => 'Ancenis-Saint-Géréon', 'country' => 'France'],
        ]]);

        $res = app(GeocodingService::class)->search('5 rue du stade ancenis');

        $this->assertSame('Rue du Stade', $res[0]['name']);
        $this->assertSame('5 Rue du Stade, 44150 Ancenis-Saint-Géréon, France', $res[0]['address']);
    }

    /** Résultat inexploitable (pas de géométrie) → ignoré, sans casser la liste ni lever d'exception. */
    public function test_un_resultat_sans_geometrie_est_ignore(): void
    {
        $this->photonFake([
            ['properties' => ['name' => 'Sans géométrie']],
            $this->feature(-1.17, 47.37, 'Complet'),
        ]);

        $res = app(GeocodingService::class)->search('piscine ancenis');

        $this->assertCount(1, $res);
        $this->assertSame('Complet', $res[0]['name']);
    }

    /**
     * `geocode()` emploie le même moteur : l'intérêt de l'opération est de n'en avoir qu'un seul —
     * un seul comportement à comprendre, une seule forme de cache.
     */
    public function test_geocode_emploie_le_meme_moteur_et_retient_le_premier(): void
    {
        $this->photonFake([$this->feature(-1.17, 47.37), $this->feature(2.35, 48.85, 'Ailleurs')]);

        $this->assertSame(['lat' => 47.37, 'lng' => -1.17], app(GeocodingService::class)->geocode('piscine ancenis'));
    }

    /**
     * Un `feature` GeoJSON Photon. L'ordre des arguments suit celui du service — longitude d'abord,
     * comme le rend le moteur.
     *
     * @return array<string, mixed>
     */
    private function feature(float $lon, float $lat, string $nom = 'Piscine de la Charbonnière', string $osmValue = 'swimming_pool'): array
    {
        return [
            'geometry' => ['coordinates' => [$lon, $lat]],
            'properties' => [
                'name' => $nom, 'street' => 'Quai', 'postcode' => '44150',
                'city' => 'Ancenis-Saint-Géréon', 'country' => 'France',
                'osm_key' => 'leisure', 'osm_value' => $osmValue,
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $features */
    private function photonFake(array $features): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => $features])]);
    }
}
