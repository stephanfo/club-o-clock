<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Location;
use App\Models\Session;
use App\Models\User;
use App\Models\WeatherCacheEntry;
use App\Services\WeatherService;
use App\Support\Weather;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

// Météo sur toute la DURÉE de la séance (#55) — et non sur son seul instant de départ.
// Toutes les requêtes sont feintes : aucun appel réseau dans la suite.
class WeatherRangeTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 47.37;

    private const LNG = -1.17;

    /**
     * Feint Open-Meteo sur des heures consécutives à partir de l'heure pleine de $debut.
     *
     * @param  array<int, array{temp:float,prob:int,mm:float,wind:float,deg:int,code:int}>  $heures
     */
    private function fakeHeures(Carbon $debut, array $heures): void
    {
        $h = ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [],
            'precipitation' => [], 'wind_speed_10m' => [], 'wind_direction_10m' => [], 'weather_code' => []];

        $curseur = $debut->copy()->setTime($debut->hour, 0, 0);
        foreach ($heures as $v) {
            $h['time'][] = $curseur->format('Y-m-d\TH:00');
            $h['temperature_2m'][] = $v['temp'];
            $h['precipitation_probability'][] = $v['prob'];
            $h['precipitation'][] = $v['mm'];
            $h['wind_speed_10m'][] = $v['wind'];
            $h['wind_direction_10m'][] = $v['deg'];
            $h['weather_code'][] = $v['code'];
            $curseur->addHour();
        }

        Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => $h])]);
    }

    /** Jeu de trois heures : 12 °C ciel dégagé → 19 °C sous l'averse, vent qui double. */
    private function troisHeures(): array
    {
        return [
            ['temp' => 12.0, 'prob' => 10, 'mm' => 0.0, 'wind' => 12.0, 'deg' => 225, 'code' => 0],
            ['temp' => 15.0, 'prob' => 40, 'mm' => 1.2, 'wind' => 18.0, 'deg' => 225, 'code' => 3],
            ['temp' => 19.0, 'prob' => 70, 'mm' => 2.0, 'wind' => 24.0, 'deg' => 90, 'code' => 80],
        ];
    }

    private function creneau(int $h = 9, int $m = 30): Carbon
    {
        return Carbon::now()->addDays(2)->setTime($h, $m, 0);
    }

    public function test_agrege_toutes_les_heures_couvertes_et_non_la_seule_premiere(): void
    {
        $debut = $this->creneau(9, 30);
        $this->fakeHeures($debut, $this->troisHeures());

        // 09:30 → 11:15 : heures 09, 10 et 11.
        $w = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $debut->copy()->addMinutes(105));

        $this->assertSame(12.0, $w['tempStart']);
        $this->assertSame(19.0, $w['tempEnd']);
        $this->assertSame(12.0, $w['windMin']);
        $this->assertSame(24.0, $w['windMax']);
        $this->assertSame(70, $w['precipProb']);          // maximum
        $this->assertSame(3.2, $w['precipMm']);           // cumul
        $this->assertSame(225, $w['windDeg']);            // secteur dominant (SO, 2 heures sur 3)
        $this->assertSame(80, $w['code']);                // le plus sévère de la fenêtre
        $this->assertSame(9, $w['hourStart']);
        $this->assertSame(11, $w['hourEnd']);
    }

    public function test_un_seul_appel_http_pour_n_heures_et_une_ligne_de_cache_par_heure(): void
    {
        $debut = $this->creneau(9, 30);
        $this->fakeHeures($debut, $this->troisHeures());

        app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $debut->copy()->addMinutes(105));

        Http::assertSentCount(1);
        $this->assertSame(3, WeatherCacheEntry::count());
    }

    public function test_deuxieme_appel_dans_le_ttl_ne_sort_pas_sur_le_reseau(): void
    {
        $debut = $this->creneau(9, 30);
        $this->fakeHeures($debut, $this->troisHeures());
        $fin = $debut->copy()->addMinutes(105);

        $premier = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);
        $second = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);

        Http::assertSentCount(1);
        $this->assertSame($premier, $second);
    }

    public function test_seance_tenant_dans_une_heure_rend_le_meme_resultat_qu_avant(): void
    {
        $debut = $this->creneau(18, 0);
        $this->fakeHeures($debut, [['temp' => 14.2, 'prob' => 30, 'mm' => 0.4, 'wind' => 12.0, 'deg' => 225, 'code' => 3]]);

        // Une séance 18:00 → 19:00 tient dans la seule heure 18 : la borne de fin tombe pile sur
        // l'heure suivante, qui n'est pas couverte.
        $w = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $debut->copy()->addHour());

        $this->assertSame(14.2, $w['tempStart']);
        $this->assertSame(14.2, $w['tempEnd']);
        $this->assertSame(12.0, $w['windMin']);
        $this->assertSame(12.0, $w['windMax']);
        $this->assertSame(0.4, $w['precipMm']);
        $this->assertSame(3, $w['code']);
        $this->assertSame(18, $w['hourStart']);
        $this->assertSame(18, $w['hourEnd']);
        $this->assertSame(1, WeatherCacheEntry::count());
    }

    public function test_une_heure_perimee_declenche_un_seul_rafraichissement(): void
    {
        $debut = $this->creneau(9, 30);
        $fin = $debut->copy()->addMinutes(105);
        $this->fakeHeures($debut, $this->troisHeures());
        app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);
        Http::assertSentCount(1);

        // Une seule des trois heures sort du TTL de 3 h.
        WeatherCacheEntry::query()->where('slot', $debut->copy()->setTime(10, 0))
            ->update(['fetched_at' => Carbon::now()->subHours(5)]);

        $w = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);

        Http::assertSentCount(2);
        $this->assertSame(12.0, $w['tempStart']);
        $this->assertSame(19.0, $w['tempEnd']);
        $this->assertSame(3, WeatherCacheEntry::count());
    }

    public function test_source_injoignable_sert_le_cache_perime_sans_perdre_les_heures_fraiches(): void
    {
        $debut = $this->creneau(9, 30);
        $fin = $debut->copy()->addMinutes(105);
        $this->fakeHeures($debut, $this->troisHeures());
        app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);

        WeatherCacheEntry::query()->where('slot', $debut->copy()->setTime(10, 0))
            ->update(['fetched_at' => Carbon::now()->subHours(5)]);
        Http::fake(['api.open-meteo.com/*' => Http::response('', 503)]);

        $w = app(WeatherService::class)->forecastRange(self::LAT, self::LNG, $debut, $fin);

        // Dégradé gracieux : on agrège sur ce dont on dispose — les trois heures sont en cache,
        // l'une périmée, aucune n'est perdue.
        $this->assertNotNull($w);
        $this->assertSame(12.0, $w['tempStart']);
        $this->assertSame(19.0, $w['tempEnd']);
        $this->assertSame(80, $w['code']);
    }

    public function test_severite_neige_forte_lemporte_sur_averses_faibles(): void
    {
        // max() sur le code WMO brut donnerait 80 : la numérotation n'est pas un ordre de sévérité.
        $this->assertSame(75, Weather::worst([80, 75]));
        $this->assertSame(75, Weather::worst([75, 80]));
        $this->assertGreaterThan(Weather::severity(80), Weather::severity(75));

        // Contrôles positifs appariés : l'orage l'emporte sur la neige, et l'intensité ordonne
        // bien l'intérieur d'une famille.
        $this->assertSame(95, Weather::worst([75, 95]));
        $this->assertSame(82, Weather::worst([80, 82, 81]));
        $this->assertSame(3, Weather::worst([0, 3, 1]));
    }

    /** Fiche d'une séance géocodée dont la météo vient d'être feinte. */
    private function fiche(Carbon $debut, int $duree): SessionShow|Testable
    {
        $coach = User::factory()->coach()->create();
        $loc = Location::create(['name' => 'Stade', 'latitude' => self::LAT, 'longitude' => self::LNG, 'created_by' => $coach->id]);
        $s = Session::create([
            'kind' => 'training', 'title' => 'Sortie longue', 'start_at' => $debut, 'duration_min' => $duree,
            'location_id' => $loc->id, 'created_by' => $coach->id,
        ]);

        return Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s]);
    }

    public function test_la_cartouche_affiche_la_plage_de_temperature_au_dela_du_seuil(): void
    {
        $debut = $this->creneau(9, 30);
        $this->fakeHeures($debut, $this->troisHeures());   // 12 °C → 19 °C : bien au-dessus de 2 °C

        $this->fiche($debut, 105)
            ->assertViewHas('weatherState', 'full')
            ->assertSee('12° → 19°')
            ->assertSee('12–24')
            ->assertSee('Prévision 09h–11h');
    }

    public function test_la_cartouche_tait_la_plage_sous_le_seuil_de_deux_degres(): void
    {
        $debut = $this->creneau(9, 30);
        $this->fakeHeures($debut, [
            ['temp' => 12.0, 'prob' => 10, 'mm' => 0.0, 'wind' => 12.0, 'deg' => 225, 'code' => 0],
            ['temp' => 13.4, 'prob' => 10, 'mm' => 0.0, 'wind' => 12.4, 'deg' => 225, 'code' => 0],
        ]);

        // Assertion négative appariée au contrôle positif du test précédent, même écran.
        $this->fiche($debut, 105)
            ->assertViewHas('weatherState', 'full')
            ->assertDontSee('→')
            ->assertSee('12°')
            ->assertSee('Prévision 09h–10h');
    }
}
