<?php

namespace Tests\Feature;

use App\Livewire\GpxRouteForm;
use App\Livewire\GpxRouteLibrary;
use App\Livewire\GpxRouteShow;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fiche parcours (PRD §4.20, J10.C) — consultation, séances liées, archivage et suppression.
 *
 * L'invariant central du jalon : archiver CONSERVE le fichier, seule la suppression admin le purge
 * (doc J10 §3, table du cycle de vie).
 */
class GpxRouteShowTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): User
    {
        return User::factory()->create(['roles' => ['athlete']]);
    }

    private function coach(): User
    {
        return User::factory()->coach()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Parcours dont le fichier existe réellement sur le disk factice. */
    private function routeWithFile(array $attributes = []): GpxRoute
    {
        Storage::fake('local');
        $route = GpxRoute::factory()->create(['gpx_path' => 'gpx/trace.gpx', ...$attributes]);
        Storage::disk('local')->put('gpx/trace.gpx', '<gpx/>');

        return $route;
    }

    /** Pas de factory sur Session (comme GpxUiTest) : création directe. */
    private function makeSession(?GpxRoute $route, ?Carbon $start = null): Session
    {
        return Session::create([
            'kind' => 'training',
            'title' => 'Sortie club',
            'start_at' => $start ?? Carbon::now()->addDay(),
            'duration_min' => 90,
            'route_id' => $route?->id,
        ]);
    }

    public function test_every_member_can_open_a_route_sheet(): void
    {
        $route = GpxRoute::factory()->create(['name' => 'Boucle de Chambord']);

        $this->actingAs($this->athlete())
            ->get(route('gpx-routes.show', $route))
            ->assertOk()
            ->assertSee('Boucle de Chambord')
            ->assertSeeLivewire(GpxRouteShow::class);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('gpx-routes.show', GpxRoute::factory()->create()))->assertRedirect();
    }

    /** `/parcours/creer` ne doit pas être capté comme un identifiant de parcours. */
    public function test_the_create_route_is_not_swallowed_by_the_show_route(): void
    {
        $this->actingAs($this->coach())
            ->get(route('gpx-routes.create'))
            ->assertOk()
            ->assertSeeLivewire(GpxRouteForm::class);
    }

    public function test_linked_sessions_are_listed_most_recent_first(): void
    {
        $route = GpxRoute::factory()->create();
        $old = $this->makeSession($route, Carbon::now()->subMonth());
        $recent = $this->makeSession($route, Carbon::now()->subDay());
        $this->makeSession(null);   // sans parcours : ne doit pas apparaître

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteShow::class, ['gpxRoute' => $route]);

        $component->assertViewHas('sessionCount', 2);
        $this->assertSame([$recent->id, $old->id], $component->viewData('sessions')->pluck('id')->all());
    }

    /** La fiche n'est pas un agenda : la liste est bornée, mais le compte total reste exact. */
    public function test_the_session_list_is_capped_but_the_count_is_not(): void
    {
        $route = GpxRoute::factory()->create();
        for ($i = 0; $i < 23; $i++) {
            $this->makeSession($route, Carbon::now()->subDays($i));
        }

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteShow::class, ['gpxRoute' => $route]);

        $this->assertCount(20, $component->viewData('sessions'));
        $component->assertViewHas('sessionCount', 23);
    }

    // ── Archivage ──────────────────────────────────────────────────────────────────────────

    public function test_a_coach_can_archive_and_restore_a_route(): void
    {
        $route = GpxRoute::factory()->create();
        $coach = $this->coach();

        Livewire::actingAs($coach)
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->call('archive')
            ->assertSet('confirmingArchive', false);

        $this->assertNotNull($route->fresh()->archived_at);
        $this->assertSame($coach->id, $route->fresh()->archived_by);

        Livewire::actingAs($coach)
            ->test(GpxRouteShow::class, ['gpxRoute' => $route->fresh()])
            ->call('restore');

        $this->assertNull($route->fresh()->archived_at);
    }

    /** L'archivage est réversible : restaurer sans le fichier n'aurait aucun sens (doc J10 §3). */
    public function test_archiving_keeps_the_gpx_file_on_disk(): void
    {
        $route = $this->routeWithFile();

        Livewire::actingAs($this->coach())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->call('archive');

        Storage::disk('local')->assertExists('gpx/trace.gpx');
    }

    public function test_an_athlete_cannot_archive_a_route(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->call('archive')
            ->assertForbidden();

        $this->assertNull($route->fresh()->archived_at);
    }

    // ── Suppression ────────────────────────────────────────────────────────────────────────

    public function test_an_admin_deletes_an_orphan_route_and_its_file(): void
    {
        $route = $this->routeWithFile();

        Livewire::actingAs($this->admin())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->call('delete')
            ->assertRedirect(route('gpx-routes.index'));

        $this->assertDatabaseMissing('gpx_routes', ['id' => $route->id]);
        Storage::disk('local')->assertMissing('gpx/trace.gpx');
    }

    /**
     * L'UI masque le bouton dès qu'une séance référence le parcours, mais une action Livewire reste
     * appelable directement : la garde du service doit tenir, et le parcours survivre.
     */
    public function test_deleting_a_used_route_is_refused_even_when_called_directly(): void
    {
        $route = $this->routeWithFile();
        $this->makeSession($route);

        Livewire::actingAs($this->admin())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertViewHas('canDelete', false)
            ->assertViewHas('sessionCountBlocksDelete', true)
            ->call('delete')
            ->assertSet('confirmingDelete', false)
            ->assertNoRedirect();

        $this->assertDatabaseHas('gpx_routes', ['id' => $route->id]);
        Storage::disk('local')->assertExists('gpx/trace.gpx');
    }

    public function test_a_coach_cannot_delete_a_route(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->coach())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertViewHas('canDelete', false)
            ->call('delete')
            ->assertForbidden();

        $this->assertDatabaseHas('gpx_routes', ['id' => $route->id]);
    }

    public function test_management_actions_are_hidden_from_athletes(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertViewHas('canArchive', false)
            ->assertViewHas('canManage', false)
            ->assertDontSee('Archiver');
    }

    // ── Rendu ──────────────────────────────────────────────────────────────────────────────

    /** Le profil altimétrique est rendu SERVEUR : pas de JS, le SVG doit être dans le HTML. */
    public function test_the_elevation_profile_is_rendered_server_side(): void
    {
        $route = GpxRoute::factory()->create();   // la factory pose 4 paires de profil

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSee('alt-profile', false)
            ->assertSee('<polyline', false);
    }

    /** Sans altimétrie, le composant ne rend rien plutôt qu'un cadre vide. */
    public function test_a_route_without_elevation_shows_no_profile(): void
    {
        $route = GpxRoute::factory()->withoutGeo()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertDontSee('alt-profile', false);
    }

    /** Une trace parfaitement plate ne doit pas provoquer de division par zéro. */
    public function test_a_flat_profile_renders_without_dividing_by_zero(): void
    {
        $route = GpxRoute::factory()->create([
            'elevation_profile' => [[0.0, 80.0], [10.0, 80.0], [20.0, 80.0]],
        ]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertOk()
            ->assertSee('alt-profile', false)
            // Trois graduations identiques n'apprendraient rien : une seule sur une trace plate.
            ->assertSeeInOrder(['alt-tick', '80 m'], false)
            ->assertDontSeeHtml('<span class="alt-tick" style="top:8.7%">');
    }

    /** L'axe Y chiffre les altitudes réelles, sinon un profil normalisé est illisible (2026-08-02). */
    public function test_the_y_axis_is_labelled_with_real_altitudes(): void
    {
        $route = GpxRoute::factory()->create([
            'elevation_profile' => [[0.0, 60.0], [10.0, 100.0], [20.0, 140.0]],
        ]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            // Bas, milieu, haut — dans cet ordre de haut en bas dans le HTML.
            ->assertSeeInOrder(['140 m', '100 m', '60 m'], false);
    }

    /**
     * L'axe X chiffre des distances RONDES (0, 5, 10… km) et non des positions régulières :
     * « 11,064 km » pile au milieu du cadre se lit moins bien qu'un « 10 km » légèrement décalé.
     * Le pas s'adapte à la longueur (2026-08-02).
     */
    public function test_the_x_axis_uses_round_distances(): void
    {
        // distance_km ET profil cohérents : le pas se calcule sur la longueur réelle (cf.
        // KmStepSyncTest), pas sur le dernier échantillon du profil.
        $route = GpxRoute::factory()->create([
            'distance_km' => 22.128,
            'elevation_profile' => [[0.0, 60.0], [11.064, 120.0], [22.128, 90.0]],
        ]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSee('alt-xaxis', false)
            // Pas de 5 km sur 22 km : 0, 5, 10, 15, 20 — et l'unité accolée au dernier.
            ->assertSeeInOrder([
                'left:0%">0',
                'left:22.596%">5',
                'left:45.192%">10',
                'left:67.787%">15',
                'left:90.383%">20',
            ], false)
            // L'unité est accolée à la dernière graduation (un `@if` Blade les sépare dans le HTML).
            ->assertSee('&nbsp;km', false)
            // La borne exacte du parcours n'est PAS une graduation : c'est le prix des valeurs rondes.
            ->assertDontSee('">22.128', false);
    }

    /** Le pas suit l'échelle : un 5 km ne peut pas être gradué tous les 5 km. */
    public function test_the_x_axis_step_adapts_to_a_short_route(): void
    {
        $route = GpxRoute::factory()->create([
            'distance_km' => 5.0,
            'elevation_profile' => [[0.0, 60.0], [2.5, 80.0], [5.0, 70.0]],
        ]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSeeInOrder([
                'left:0%">0',
                'left:20%">1',
                'left:40%">2',
                'left:60%">3',
                'left:80%">4',
                'left:100%">5',
            ], false);
    }

    /**
     * Le recadrage des libellés de bord suit la POSITION, pas l'index. La dernière graduation ronde
     * tombe souvent vers 90 %, où un libellé centré tient encore : l'aligner à droite le décalerait
     * de sa propre abscisse (13 px mesurés à 90,4 % sur une réglette de 300 px). Revue 2026-08-02.
     */
    public function test_only_edge_labels_are_realigned(): void
    {
        // 22,128 km → dernière graduation « 20 » à 90,383 % : centrée, donc PAS de classe de bord.
        $route = GpxRoute::factory()->create([
            'distance_km' => 22.128,
            'elevation_profile' => [[0.0, 60.0], [11.064, 120.0], [22.128, 90.0]],
        ]);

        $html = Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->html();

        $this->assertStringNotContainsString('alt-xtick-last', $html,
            'À 90,4 % le libellé tient centré : le recadrage droit le décalerait de son abscisse.');
        // Le 0, lui, est bien au bord gauche.
        $this->assertStringContainsString('alt-xtick-first', $html);

        // 5 km → dernière graduation pile à 100 % : là, le recadrage est nécessaire.
        $exact = GpxRoute::factory()->create([
            'distance_km' => 5.0,
            'elevation_profile' => [[0.0, 60.0], [2.5, 80.0], [5.0, 70.0]],
        ]);

        $this->assertStringContainsString('alt-xtick-last', Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $exact])->html());
    }

    /**
     * Une graduation solitaire n'informe de rien et coûterait quand même la hauteur de la réglette :
     * sous le premier pas, pas d'axe X du tout.
     */
    public function test_a_very_short_route_gets_no_x_axis(): void
    {
        $route = GpxRoute::factory()->create([
            'distance_km' => 0.4,
            'elevation_profile' => [[0.0, 60.0], [0.2, 62.0], [0.4, 61.0]],
        ]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSee('alt-profile', false)
            ->assertDontSee('alt-xaxis', false)
            // …et le cadre ne paie pas les 22 px de la réglette absente (revue 2026-08-02).
            ->assertDontSee('alt-xruled', false);
    }

    // ── Données géographiques absentes ─────────────────────────────────────────────────────

    /**
     * Une trace dont le bloc géo a été rejeté (ou déposée avant que l'extraction n'existe) sort
     * silencieusement des filtres Direction et Forme : la fiche doit le dire.
     */
    public function test_a_route_without_geo_shows_a_notice(): void
    {
        $route = GpxRoute::factory()->withoutGeo()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertViewHas('missingGeo', true)
            ->assertSee('Données géographiques absentes');
    }

    public function test_a_route_with_geo_shows_no_notice(): void
    {
        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => GpxRoute::factory()->create()])
            ->assertViewHas('missingGeo', false)
            ->assertDontSee('Données géographiques absentes');
    }

    /** Seul l'encadrement peut y remédier : l'invitation à redéposer lui est réservée. */
    public function test_only_managers_are_invited_to_re_upload(): void
    {
        $route = GpxRoute::factory()->withoutGeo()->create();

        Livewire::actingAs($this->coach())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSee('Redépose le fichier');

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertDontSee('Redépose le fichier');
    }

    /**
     * La carte de tracé est verrouillée au montage (2026-08-02) : sans ça, ses 400 px capturent le
     * scroll de la page. Le test couvre le câblage Blade→Alpine, pas le comportement Leaflet.
     */
    public function test_the_trace_map_is_lockable(): void
    {
        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => GpxRoute::factory()->create()])
            ->assertSee('lockable: true', false)
            ->assertSee('toggleLock()', false)
            ->assertSee('loc-map-veil', false);
    }

    /**
     * Retour contextuel (2026-08-02) : le chevron tente `window.clubBack()` avant de suivre son
     * href, sinon revenir d'une fiche ouverte depuis une séance atterrissait sur la bibliothèque.
     *
     * Le `wire:navigate` doit rester ABSENT de ce lien : il navigue dès `mousedown`, donc avant tout
     * `onclick`, et court-circuiterait le retour historique (même piège que wire:click empilé).
     */
    public function test_the_back_chevron_prefers_history_over_its_fallback_url(): void
    {
        $html = Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => GpxRoute::factory()->create()])
            ->assertSee('window.clubBack', false)
            ->assertSee(route('gpx-routes.index'), false)
            ->html();

        foreach (explode('<a ', $html) as $anchor) {
            if (str_contains($anchor, 'window.clubBack')) {
                $this->assertStringNotContainsString('wire:navigate', explode('</a>', $anchor)[0]);
            }
        }
    }

    public function test_the_sheet_links_to_the_gpx_download(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])
            ->assertSee(route('gpx-routes.gpx', $route), false)
            ->assertSee('Télécharger le GPX');
    }

    public function test_the_library_cards_link_to_the_sheet(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->assertSee(route('gpx-routes.show', $route), false);
    }
}
