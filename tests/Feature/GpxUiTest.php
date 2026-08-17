<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Upload / téléchargement GPX depuis le formulaire de séance (PRD §4.13.2, §4.20).
// Depuis J10 le fichier n'appartient plus à la séance : la dropzone crée une GpxRoute partagée
// et la séance ne porte qu'un route_id.
class GpxUiTest extends TestCase
{
    use RefreshDatabase;

    private function discipline(): Discipline
    {
        return Discipline::create(['label' => 'Vélo', 'sort_order' => 0]);
    }

    public function test_gpx_upload_creates_route_and_attaches_it(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Sortie GPX')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('gpxFile', UploadedFile::fake()->create('sortie.gpx', 80, 'application/gpx+xml'))
            ->set('gpxStats', ['name' => 'sortie.gpx', 'sizeKo' => 80, 'distanceKm' => 51.5, 'dplus' => 320, 'dmoins' => 315, 'altMin' => 2, 'altMax' => 48, 'pointCount' => 999999999999])
            ->call('save')
            ->assertHasNoErrors();

        $s = Session::where('title', 'Sortie GPX')->first();
        $this->assertNotNull($s->route_id);

        $route = $s->gpxRoute;
        Storage::disk('local')->assertExists($route->gpx_path);
        $this->assertSame('51.5', (string) $route->distance_km);
        // Clamp défensif sur des stats client non fiables (pointCount borné à 10 000 000).
        $this->assertSame(10000000, $route->point_count);
        // Le nom du parcours est repris du fichier, la discipline héritée de la séance.
        $this->assertSame('sortie', $route->name);
        $this->assertSame($disc->id, $route->discipline_id);
        // hash_file lit des octets, sans jamais interpréter le XML (cadrage §7.6).
        $this->assertNotNull($route->gpx_hash);
    }

    public function test_non_gpx_extension_is_rejected(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Mauvais fichier')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('gpxFile', UploadedFile::fake()->create('photo.png', 80))
            ->call('save')
            ->assertHasErrors('gpxFile');
    }

    public function test_existing_route_can_be_selected_without_upload(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();
        $route = GpxRoute::factory()->create(['name' => 'Boucle Loire']);

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Avec parcours existant')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 90)
            ->set('route_id', $route->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($route->id, Session::where('title', 'Avec parcours existant')->first()->route_id);
        // Aucun doublon créé : on a réutilisé le parcours, pas déposé un fichier.
        $this->assertSame(1, GpxRoute::count());
    }

    /**
     * `pickRoute()` refuse déjà un parcours archivé, mais `route_id` est une propriété PUBLIQUE :
     * sans règle de validation, les deux chemins d'entrée divergent et un parcours retiré de la
     * bibliothèque se rattache quand même à une séance (revue 2026-08-02).
     */
    public function test_an_archived_route_cannot_be_attached_through_route_id(): void
    {
        $coach = User::factory()->coach()->create();
        $archived = GpxRoute::factory()->create(['archived_at' => Carbon::now()->subDay()]);

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Parcours retiré')
            ->set('discipline_id', $this->discipline()->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 90)
            ->set('route_id', $archived->id)
            ->call('save')
            ->assertHasErrors('route_id');
    }

    /**
     * Revers de la règle ci-dessus : un parcours archivé APRÈS coup reste attaché à ses séances.
     * Éditer une telle séance (changer son titre, son horaire…) ne doit pas être bloqué par un
     * parcours qu'on ne touche même pas.
     */
    public function test_a_session_keeps_saving_when_its_route_was_archived_afterwards(): void
    {
        $coach = User::factory()->coach()->create();
        $route = GpxRoute::factory()->create();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Avant archivage', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'created_by' => $coach->id,
            'route_id' => $route->id,
        ]);
        $route->forceFill(['archived_at' => Carbon::now()])->save();

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $s])
            ->set('title', 'Après archivage')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($route->id, $s->fresh()->route_id);
    }

    public function test_remove_gpx_detaches_but_keeps_route_and_file(): void
    {
        Storage::fake('local');
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/old.gpx']);
        Storage::disk('local')->put('gpx/old.gpx', '<gpx/>');

        $s = Session::create([
            'kind' => 'training', 'title' => 'Avec GPX', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'created_by' => $coach->id,
            'route_id' => $route->id,
        ]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $s])
            ->call('removeGpx')
            ->call('save')
            ->assertHasNoErrors();

        // La séance est détachée…
        $this->assertNull($s->fresh()->route_id);
        // …mais le parcours est partagé : ni lui ni son fichier ne disparaissent (doc J10 §3).
        $this->assertNotNull($route->fresh());
        Storage::disk('local')->assertExists('gpx/old.gpx');
    }

    public function test_member_can_download_gpx(): void
    {
        Storage::fake('local');
        $route = GpxRoute::factory()->create([
            'gpx_path' => 'gpx/x.gpx',
            'gpx_original_name' => 'sortie.gpx',
        ]);
        Storage::disk('local')->put('gpx/x.gpx', '<gpx>data</gpx>');

        $member = User::factory()->create(['email_verified_at' => now()]);
        $res = $this->actingAs($member)->get(route('gpx-routes.gpx', $route));

        $res->assertOk();
        $res->assertHeader('content-type', 'application/gpx+xml');
    }

    public function test_download_404_when_file_missing(): void
    {
        Storage::fake('local');
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/absent.gpx']);

        $member = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($member)->get(route('gpx-routes.gpx', $route))->assertNotFound();
    }

    /** Même verrou que la carte du lieu : l'onglet Parcours ne doit pas capturer le scroll. */
    public function test_the_session_trace_map_is_lockable(): void
    {
        $route = GpxRoute::factory()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Sortie', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'route_id' => $route->id,
        ]);

        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get(route('sessions.show', $session))
            ->assertOk()
            ->assertSee('lockable: true', false)
            ->assertSee('loc-map-veil', false);
    }

    /** Le parcours est partagé : sa fiche est atteignable depuis l'onglet Parcours de la séance (J10.C). */
    public function test_session_sheet_links_to_the_route_sheet(): void
    {
        $route = GpxRoute::factory()->create(['name' => 'Boucle Loire']);
        $session = Session::create([
            'kind' => 'training', 'title' => 'Sortie avec parcours',
            'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90,
            'route_id' => $route->id,
        ]);

        $member = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($member)
            ->get(route('sessions.show', $session))
            ->assertOk()
            ->assertSee(route('gpx-routes.show', $route), false);
    }
}
