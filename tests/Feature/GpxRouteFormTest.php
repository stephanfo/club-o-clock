<?php

namespace Tests\Feature;

use App\Livewire\GpxRouteForm;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Models\User;
use App\Services\GpxRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Formulaire de bibliothèque + service (PRD §4.20). Écriture réservée à l'encadrement ;
// tout passage de fichier transite par GpxRouteService.
class GpxRouteFormTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function clientStats(): array
    {
        return [
            'name' => 'boucle.gpx',
            'sizeKo' => 42,
            'distanceKm' => 42.5,
            'dplus' => 320,
            'dmoins' => 315,
            'altMin' => 62,
            'altMax' => 148,
            'pointCount' => 1840,
            'durationMin' => 95,
            'geo' => [
                'start' => ['lat' => 47.5500, 'lon' => 1.3000],
                'end' => ['lat' => 47.5501, 'lon' => 1.3001],
                'isLoop' => true,
                'bbox' => ['minLat' => 47.5500, 'minLon' => 1.3000, 'maxLat' => 47.6200, 'maxLon' => 1.4000],
                'bearing' => 45,
                'sector' => 'NE',
                'polyline' => [[47.55, 1.30], [47.58, 1.35], [47.62, 1.40], [47.55, 1.30]],
                'elevationProfile' => [[0.0, 62.0], [21.0, 148.0], [42.5, 64.0]],
            ],
        ];
    }

    public function test_coach_can_create_route_with_geo(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $disc = Discipline::create(['label' => 'Vélo', 'sort_order' => 0]);

        Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Boucle Loire')
            ->set('discipline_id', $disc->id)
            ->set('gpxFile', UploadedFile::fake()->create('boucle.gpx', 42, 'application/gpx+xml'))
            ->set('gpxStats', $this->clientStats())
            ->call('save')
            ->assertHasNoErrors();

        $route = GpxRoute::where('name', 'Boucle Loire')->first();
        $this->assertNotNull($route);
        Storage::disk('local')->assertExists($route->gpx_path);
        $this->assertSame('NE', $route->sector);
        $this->assertTrue($route->is_loop);
        $this->assertCount(4, $route->polyline);
        $this->assertCount(3, $route->elevation_profile);
        $this->assertSame($coach->id, $route->created_by);
    }

    public function test_athlete_cannot_open_the_form(): void
    {
        $athlete = User::factory()->create(['email_verified_at' => now()]);

        Livewire::actingAs($athlete)->test(GpxRouteForm::class)->assertForbidden();
    }

    public function test_non_gpx_extension_is_rejected(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Mauvais fichier')
            ->set('gpxFile', UploadedFile::fake()->create('photo.png', 12))
            ->call('save')
            ->assertHasErrors('gpxFile');
    }

    public function test_file_is_required_on_creation_only(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Sans fichier')
            ->call('save')
            ->assertHasErrors('gpxFile');

        // En édition, ne pas redéposer de fichier est légitime (on renomme, on décrit…).
        $route = GpxRoute::factory()->create(['name' => 'Existant']);
        Livewire::actingAs($coach)->test(GpxRouteForm::class, ['gpxRoute' => $route])
            ->set('name', 'Renommé')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renommé', $route->fresh()->name);
    }

    public function test_out_of_range_geo_nulls_columns_but_still_creates_route(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();

        $stats = $this->clientStats();
        $stats['geo']['start'] = ['lat' => 91, 'lon' => 1.3];   // latitude impossible

        Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Géo cassée')
            ->set('gpxFile', UploadedFile::fake()->create('boucle.gpx', 42))
            ->set('gpxStats', $stats)
            ->call('save')
            ->assertHasNoErrors();

        $route = GpxRoute::where('name', 'Géo cassée')->first();
        // Le parcours existe et reste téléchargeable : seule la géo est écartée.
        $this->assertNotNull($route);
        $this->assertNull($route->start_lat);
        $this->assertNull($route->sector);
        $this->assertSame('42.5', (string) $route->distance_km);
    }

    public function test_polyline_is_truncated_to_hard_cap(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();

        $stats = $this->clientStats();
        $stats['geo']['polyline'] = array_fill(0, 10000, [47.55, 1.30]);

        Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Payload hostile')
            ->set('gpxFile', UploadedFile::fake()->create('boucle.gpx', 42))
            ->set('gpxStats', $stats)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertCount(250, GpxRoute::where('name', 'Payload hostile')->first()->polyline);
    }

    public function test_duplicate_is_flagged_and_blocks_until_acknowledged(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $service = app(GpxRouteService::class);

        $file = UploadedFile::fake()->createWithContent('boucle.gpx', '<gpx>identique</gpx>');
        $existing = $service->createFromUpload($file, ['name' => 'Boucle Loire 42 km'], null, $coach);

        $component = Livewire::actingAs($coach)->test(GpxRouteForm::class)
            ->set('name', 'Le même en double')
            ->set('gpxFile', UploadedFile::fake()->createWithContent('autre-nom.gpx', '<gpx>identique</gpx>'));

        // Détection au dépôt : on signale le parcours existant.
        $component->assertSet('duplicateId', $existing->id)
            ->assertSet('duplicateName', 'Boucle Loire 42 km');

        // Tant que ce n'est pas levé, la création est refusée.
        $component->call('save');
        $this->assertNull(GpxRoute::where('name', 'Le même en double')->first());

        // « Créer quand même » : on signale, on ne bloque pas définitivement.
        $component->call('acknowledgeDuplicate')->call('save')->assertHasNoErrors();
        $this->assertNotNull(GpxRoute::where('name', 'Le même en double')->first());
    }

    public function test_replacing_file_deletes_the_previous_one(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $service = app(GpxRouteService::class);

        $route = $service->createFromUpload(
            UploadedFile::fake()->createWithContent('v1.gpx', '<gpx>v1</gpx>'),
            ['name' => 'Trace'],
            null,
            $coach,
        );
        $oldPath = $route->gpx_path;

        Livewire::actingAs($coach)->test(GpxRouteForm::class, ['gpxRoute' => $route])
            ->set('gpxFile', UploadedFile::fake()->createWithContent('v2.gpx', '<gpx>v2</gpx>'))
            ->call('save')
            ->assertHasNoErrors();

        $route->refresh();
        $this->assertNotSame($oldPath, $route->gpx_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($route->gpx_path);
    }

    // ── Cycle de vie des fichiers (doc J10 §3) ──

    public function test_service_refuses_to_delete_a_route_used_by_a_session(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/used.gpx']);
        Storage::disk('local')->put('gpx/used.gpx', '<gpx/>');

        Session::create([
            'kind' => 'training', 'title' => 'Utilise le parcours',
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90,
            'created_by' => $admin->id, 'route_id' => $route->id,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(GpxRouteService::class)->delete($route, $admin);
        } finally {
            // nullOnDelete aurait vidé la séance de son parcours en silence : rien ne doit bouger.
            $this->assertNotNull($route->fresh());
            Storage::disk('local')->assertExists('gpx/used.gpx');
        }
    }

    public function test_service_deletes_orphan_route_and_its_file(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/orphan.gpx']);
        Storage::disk('local')->put('gpx/orphan.gpx', '<gpx/>');

        app(GpxRouteService::class)->delete($route, $admin);

        $this->assertNull(GpxRoute::find($route->id));
        Storage::disk('local')->assertMissing('gpx/orphan.gpx');
    }

    public function test_archiving_keeps_the_file(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/archived.gpx']);
        Storage::disk('local')->put('gpx/archived.gpx', '<gpx/>');

        $service = app(GpxRouteService::class);
        $service->archive($route, $coach);

        $this->assertTrue($route->fresh()->isArchived());
        // Restaurer un parcours sans sa trace n'aurait aucun sens : le fichier survit.
        Storage::disk('local')->assertExists('gpx/archived.gpx');
        $this->assertSame(0, GpxRoute::query()->active()->count());

        $service->restore($route, $coach);
        $this->assertSame(1, GpxRoute::query()->active()->count());
    }
}
