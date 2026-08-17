<?php

namespace Tests\Feature;

use App\Livewire\Admin\CatalogueManager;
use App\Livewire\SessionShow;
use App\Models\Location;
use App\Models\Session;
use App\Models\User;
use App\Models\WeatherCacheEntry;
use App\Services\GeocodingService;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

// Géocodage Nominatim + météo Open-Meteo (PRD §4.13.4/.5).
class WeatherGeocodingTest extends TestCase
{
    use RefreshDatabase;

    public function test_geocode_returns_coords(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '47.3700000', 'lon' => '-1.1700000'],
        ])]);

        $coords = app(GeocodingService::class)->geocode('Piscine d\'Ancenis');

        $this->assertEqualsWithDelta(47.37, $coords['lat'], 0.0001);
        $this->assertEqualsWithDelta(-1.17, $coords['lng'], 0.0001);
    }

    public function test_geocode_returns_null_on_empty_result(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->assertNull(app(GeocodingService::class)->geocode('lieu introuvable xyz'));
    }

    public function test_search_maps_structured_results_and_caches(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            [
                'name' => 'Piscine Alphéa',
                'display_name' => 'Piscine Alphéa, 12 Rue du Stade, 44150 Ancenis, France',
                'addresstype' => 'swimming_pool',
                'address' => ['house_number' => '12', 'road' => 'Rue du Stade', 'postcode' => '44150', 'city' => 'Ancenis', 'country' => 'France'],
                'lat' => '47.3700000', 'lon' => '-1.1700000',
            ],
        ])]);

        $res = app(GeocodingService::class)->search('piscine ancenis');

        $this->assertCount(1, $res);
        $this->assertSame('Piscine Alphéa', $res[0]['name']);
        $this->assertSame('12 Rue du Stade, 44150 Ancenis, France', $res[0]['address']);
        $this->assertSame('Piscine', $res[0]['type']);
        $this->assertEqualsWithDelta(47.37, $res[0]['lat'], 0.0001);

        // 2e appel identique : cache frais → pas de nouvel appel HTTP.
        app(GeocodingService::class)->search('piscine ancenis');
        Http::assertSentCount(1);
    }

    public function test_search_falls_back_to_display_name_when_no_address_block(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Quelque part, Loire-Atlantique', 'lat' => '47.0', 'lon' => '-1.0'],
        ])]);

        $res = app(GeocodingService::class)->search('quelque part');

        $this->assertSame('Quelque part', $res[0]['name']);
        $this->assertSame('Quelque part, Loire-Atlantique', $res[0]['address']);
        $this->assertNull($res[0]['type']);
    }

    public function test_search_skips_short_queries(): void
    {
        Http::fake();

        $this->assertSame([], app(GeocodingService::class)->search('abc'));
        Http::assertNothingSent();
    }

    public function test_search_returns_empty_on_failure(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

        $this->assertSame([], app(GeocodingService::class)->search('lieu introuvable'));
    }

    public function test_catalogue_pick_suggestion_fills_all_fields(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            [
                'name' => 'Piscine Alphéa',
                'display_name' => 'Piscine Alphéa, 12 Rue du Stade, 44150 Ancenis, France',
                'addresstype' => 'swimming_pool',
                'address' => ['house_number' => '12', 'road' => 'Rue du Stade', 'postcode' => '44150', 'city' => 'Ancenis', 'country' => 'France'],
                'lat' => '47.3700000', 'lon' => '-1.1700000',
            ],
        ])]);
        $admin = User::factory()->admin()->create();

        // Champs nom/type laissés vides → la sélection les remplit aussi (clic = tout auto-rempli).
        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'location'])
            ->call('startAdd')
            ->set('form.address', 'piscine ancenis')
            ->assertCount('addressSuggestions', 1)
            ->call('pickSuggestion', 0)
            ->assertSet('form.name', 'Piscine Alphéa')
            ->assertSet('form.kind', 'Piscine')
            ->assertSet('form.address', '12 Rue du Stade, 44150 Ancenis, France')
            ->assertSet('form.latitude', 47.37)
            ->assertSet('form.longitude', -1.17)
            ->assertCount('addressSuggestions', 0);
    }

    public function test_catalogue_pick_suggestion_keeps_user_name(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            [
                'name' => 'Piscine Alphéa',
                'display_name' => 'Piscine Alphéa, Ancenis',
                'addresstype' => 'swimming_pool',
                'address' => ['road' => 'Rue du Stade', 'city' => 'Ancenis'],
                'lat' => '47.37', 'lon' => '-1.17',
            ],
        ])]);
        $admin = User::factory()->admin()->create();

        // Nom déjà saisi par le coach → préservé (la suggestion ne l'écrase pas).
        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'location'])
            ->call('startAdd')
            ->set('form.name', 'Piscine du club')
            ->set('form.address', 'piscine ancenis')
            ->call('pickSuggestion', 0)
            ->assertSet('form.name', 'Piscine du club');
    }

    private function openMeteoFake(Carbon $slot): void
    {
        $time = $slot->copy()->setTime($slot->hour, 0, 0)->format('Y-m-d\TH:00');
        Http::fake(['api.open-meteo.com/*' => Http::response([
            'hourly' => [
                'time' => [$time],
                'temperature_2m' => [14.2],
                'precipitation_probability' => [30],
                'precipitation' => [0.4],
                'wind_speed_10m' => [12.0],
                'wind_direction_10m' => [225],
                'weather_code' => [3],
            ],
        ])]);
    }

    public function test_weather_forecast_extracts_slot_and_caches(): void
    {
        $slot = Carbon::now()->addDays(2)->setTime(18, 0);
        $this->openMeteoFake($slot);

        $w = app(WeatherService::class)->forecast(47.37, -1.17, $slot);

        $this->assertSame(14.2, $w['temp']);
        $this->assertSame(30, $w['precipProb']);
        $this->assertSame(3, $w['code']);
        $this->assertSame(1, WeatherCacheEntry::count());

        // 2e appel : cache frais → pas de nouvel appel HTTP.
        app(WeatherService::class)->forecast(47.37, -1.17, $slot);
        Http::assertSentCount(1);
    }

    public function test_weather_returns_null_outside_window(): void
    {
        $slot = Carbon::now()->addDays(30)->setTime(10, 0); // > J-16
        Http::fake();

        $this->assertNull(app(WeatherService::class)->forecast(47.37, -1.17, $slot));
        Http::assertNothingSent();
    }

    public function test_fiche_shows_full_cartouche_for_geocoded_future_session(): void
    {
        $slot = Carbon::now()->addDays(3)->setTime(19, 0);
        $this->openMeteoFake($slot);

        $coach = User::factory()->coach()->create();
        $loc = Location::create(['name' => 'Stade', 'latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $coach->id]);
        $s = Session::create([
            'kind' => 'training', 'title' => 'CAP', 'start_at' => $slot, 'duration_min' => 60,
            'location_id' => $loc->id, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertViewHas('weatherState', 'full')
            ->assertSee('Open-Meteo');
    }

    public function test_fiche_nogeo_when_location_not_geocoded(): void
    {
        $coach = User::factory()->coach()->create();
        $s = Session::create([
            'kind' => 'training', 'title' => 'CAP', 'start_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'duration_min' => 60, 'location_text' => 'au parc', 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertViewHas('weatherState', 'nogeo');
    }

    public function test_catalogue_geocode_action_fills_coords(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '47.3700000', 'lon' => '-1.1700000'],
        ])]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'location'])
            ->call('startAdd')
            ->set('form.name', 'Piscine')
            ->set('form.address', "Piscine d'Ancenis")
            ->call('geocode')
            ->assertSet('form.latitude', 47.37)
            ->assertSet('form.longitude', -1.17);
    }

    public function test_refresh_command_warms_cache(): void
    {
        $slot = Carbon::now()->addDays(4)->setTime(8, 0);
        $this->openMeteoFake($slot);

        $coach = User::factory()->coach()->create();
        $loc = Location::create(['name' => 'Stade', 'latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $coach->id]);
        Session::create([
            'kind' => 'training', 'title' => 'CAP', 'start_at' => $slot, 'duration_min' => 60,
            'location_id' => $loc->id, 'created_by' => $coach->id,
        ]);

        $this->artisan('weather:refresh')->assertSuccessful();
        $this->assertSame(1, WeatherCacheEntry::count());
    }
}
