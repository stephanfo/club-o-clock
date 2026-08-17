<?php

namespace Tests\Feature;

use App\Http\Controllers\GpxRouteTracesController;
use App\Livewire\GpxRouteLibrary;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Carte d'ensemble de la bibliothèque (J10.C bis, PRD §4.20) : bascule liste/carte du composant
// + endpoint JSON des tracés simplifiés. Le rendu Leaflet lui-même n'est pas testable ici (JS).
class GpxRouteTracesTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_traces_endpoint_requires_authentication(): void
    {
        $this->get(route('gpx-routes.traces'))->assertRedirect(route('login'));
    }

    public function test_a_member_gets_the_simplified_traces(): void
    {
        $route = GpxRoute::factory()->create(['name' => 'Boucle Loire']);

        $res = $this->actingAs($this->member())->get(route('gpx-routes.traces'));

        $res->assertOk()
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('routes.0.id', $route->id)
            ->assertJsonPath('routes.0.name', 'Boucle Loire')
            ->assertJsonPath('routes.0.url', route('gpx-routes.show', $route))
            ->assertJsonPath('routes.0.sector', 'NE')
            ->assertJsonPath('routes.0.distanceKm', 42.5)
            ->assertJsonPath('routes.0.dplus', 320)
            // 320 m D+ / 42,5 km = 7,5 m/km, au-delà de GRADE_HILLY_MAX (7,3) : le libellé calculé
            // par le modèle voyage avec le tracé, la carte ne le recalcule pas.
            ->assertJsonPath('routes.0.grade', 'Exigeant');

        // La polyline est servie telle qu'elle est stockée : aucun re-parsing GPX côté serveur.
        $this->assertSame($route->polyline, $res->json('routes.0.points'));
    }

    /** Le fichier n'est JAMAIS relu : seule la colonne compte, même si le GPX a disparu du disque. */
    public function test_traces_do_not_depend_on_the_gpx_file(): void
    {
        GpxRoute::factory()->create(['gpx_path' => 'gpx/absent.gpx']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonCount(1, 'routes');
    }

    public function test_routes_without_a_polyline_are_omitted(): void
    {
        GpxRoute::factory()->create(['name' => 'Avec tracé']);
        GpxRoute::factory()->create(['name' => 'Sans tracé', 'polyline' => null]);

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.name', 'Avec tracé');
    }

    public function test_archived_routes_are_hidden_from_the_map(): void
    {
        GpxRoute::factory()->create(['name' => 'Active']);
        GpxRoute::factory()->archived()->create(['name' => 'Archivée']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.name', 'Active');
    }

    /** Un simple membre ne peut pas voir les archivés en forçant le paramètre : la garde est serveur. */
    public function test_a_member_cannot_reveal_archived_routes_via_the_query_string(): void
    {
        GpxRoute::factory()->archived()->create(['name' => 'Archivée']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces', ['archived' => 1]))
            ->assertOk()
            ->assertJsonCount(0, 'routes');
    }

    public function test_a_coach_can_include_archived_routes(): void
    {
        GpxRoute::factory()->archived()->create(['name' => 'Archivée']);

        $this->actingAs(User::factory()->coach()->create())
            ->get(route('gpx-routes.traces', ['archived' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'routes');
    }

    /** Les filtres de la bibliothèque s'appliquent à la carte : c'est la même requête (tracesQuery). */
    public function test_the_map_honours_the_library_filters(): void
    {
        GpxRoute::factory()->create(['name' => 'Nord-Est', 'sector' => 'NE']);
        GpxRoute::factory()->create(['name' => 'Sud-Ouest', 'sector' => 'SO']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces', ['sector' => ['SO']]))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.name', 'Sud-Ouest');
    }

    /** Multi-sélection : au sein d'un filtre les valeurs s'unissent, comme dans la liste. */
    public function test_several_sectors_are_a_union(): void
    {
        GpxRoute::factory()->create(['sector' => 'NE']);
        GpxRoute::factory()->create(['sector' => 'SO']);
        GpxRoute::factory()->create(['sector' => 'S']);

        $this->actingAs($this->member())
            ->get(route('gpx-routes.traces', ['sector' => ['NE', 'SO']]))
            ->assertOk()
            ->assertJsonCount(2, 'routes');
    }

    /** Un filtre scalaire (`?sector=SO`) doit marcher autant que la forme tableau. */
    public function test_a_scalar_filter_is_accepted(): void
    {
        GpxRoute::factory()->create(['name' => 'Sud-Ouest', 'sector' => 'SO']);
        GpxRoute::factory()->create(['sector' => 'NE']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces', ['sector' => 'SO']))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.name', 'Sud-Ouest');
    }

    /** Les paramètres viennent de l'URL : une valeur forgée ne doit ni filtrer, ni faire planter. */
    public function test_a_forged_filter_value_is_ignored(): void
    {
        GpxRoute::factory()->create();

        $this->actingAs($this->member())
            ->get(route('gpx-routes.traces', ['grade' => ['hack'], 'shape' => ['<script>']]))
            ->assertOk()
            ->assertJsonCount(1, 'routes');
    }

    /** Paramètre imbriqué (`?sector[][]=x`) : aplati puis rejeté, jamais de TypeError. */
    public function test_a_nested_filter_parameter_does_not_break_the_endpoint(): void
    {
        GpxRoute::factory()->create();

        $this->actingAs($this->member())
            ->get(route('gpx-routes.traces').'?sector[0][0]=NE')
            ->assertOk()
            ->assertJsonCount(1, 'routes');
    }

    public function test_the_search_term_applies_to_the_map(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle Loire']);
        GpxRoute::factory()->create(['name' => 'Sortie Sologne']);

        $this->actingAs($this->member())->get(route('gpx-routes.traces', ['search' => 'Sologne']))
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.name', 'Sortie Sologne');
    }

    public function test_the_response_is_capped_and_reports_truncation(): void
    {
        // Un de plus que le plafond : la réponse doit s'arrêter au plafond ET l'annoncer.
        GpxRoute::factory()->count(GpxRouteTracesController::MAX_TRACES + 1)->create();

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonCount(GpxRouteTracesController::MAX_TRACES, 'routes')
            ->assertJsonPath('truncated', true);
    }

    /**
     * Cas limite du plafond : à EXACTEMENT MAX_TRACES, rien n'a été coupé. Signaler la troncature
     * afficherait l'avertissement « affichage limité aux 120 premiers » sur un rendu pourtant
     * exhaustif (off-by-one `>=` corrigé le 2026-08-02).
     */
    public function test_exactly_the_cap_is_not_reported_as_truncated(): void
    {
        GpxRoute::factory()->count(GpxRouteTracesController::MAX_TRACES)->create();

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonCount(GpxRouteTracesController::MAX_TRACES, 'routes')
            ->assertJsonPath('truncated', false);
    }

    public function test_the_discipline_label_travels_with_each_trace(): void
    {
        $disc = Discipline::create(['label' => 'Vélo', 'sort_order' => 0]);
        GpxRoute::factory()->create(['discipline_id' => $disc->id]);

        $this->actingAs($this->member())->get(route('gpx-routes.traces'))
            ->assertOk()
            ->assertJsonPath('routes.0.discipline', 'Vélo');
    }

    // ─── Bascule liste / carte ───

    public function test_the_library_starts_in_list_mode(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle Loire']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->assertSet('mode', 'list')
            ->assertSee('Boucle Loire')
            ->assertDontSee('gpxRoutesMap', false);
    }

    public function test_switching_to_map_mode_renders_the_island(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle Loire']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->assertSet('mode', 'map')
            ->assertSee('gpxRoutesMap', false)
            // L'îlot doit être ignoré par Livewire, sinon un re-render détruirait les tuiles Leaflet.
            ->assertSee('wire:ignore', false)
            // Les cartes de la liste ne sont plus rendues : un seul mode à la fois.
            ->assertDontSee('Boucle Loire');
    }

    /** `mode` vient de l'URL : une valeur inconnue retombe sur la liste, jamais sur un écran vide. */
    public function test_an_unknown_mode_falls_back_to_the_list(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle Loire']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'galerie')
            ->assertSet('mode', 'list')
            ->assertSee('Boucle Loire');
    }

    /** L'URL des tracés porte les filtres : c'est ce que l'îlot reçoit à son montage. */
    public function test_the_traces_url_carries_the_current_filters(): void
    {
        GpxRoute::factory()->create(['sector' => 'SO']);

        $html = Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->call('toggle', 'sector', 'SO')
            ->html();

        $this->assertStringContainsString('parcours-traces', $html);
        $this->assertStringContainsString('sector', $html);
        $this->assertStringContainsString('SO', $html);
    }

    /**
     * Régression 2026-08-02 : la carte ne se mettait pas à jour quand on filtrait depuis le mode
     * carte. L'îlot est en `wire:ignore`, donc ses ATTRIBUTS ne sont jamais re-rendus — un
     * `x-effect` interpolé par Blade reste figé sur l'URL du montage. Seul un événement, qui ne
     * passe pas par le DOM, franchit wire:ignore.
     */
    public function test_toggling_a_chip_notifies_the_map_with_the_new_url(): void
    {
        GpxRoute::factory()->create(['sector' => 'SO']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->call('toggle', 'sector', 'SO')
            ->assertDispatched(
                'gpx-routes-filtered',
                fn (string $event, array $params) => str_contains($params['url'], 'sector')
                    && str_contains($params['url'], 'SO'),
            );
    }

    /** La recherche passe par wire:model.live → updated(), l'autre voie vers notifyMap(). */
    public function test_searching_notifies_the_map(): void
    {
        GpxRoute::factory()->create(['name' => 'Sortie Sologne']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->set('search', 'Sologne')
            ->assertDispatched(
                'gpx-routes-filtered',
                fn (string $event, array $params) => str_contains($params['url'], 'Sologne'),
            );
    }

    /** Réinitialiser doit rendre la carte à son jeu complet — et ne pas éjecter du mode carte. */
    public function test_resetting_filters_notifies_the_map_and_stays_on_it(): void
    {
        GpxRoute::factory()->create(['sector' => 'SO']);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->call('toggle', 'sector', 'SO')
            ->call('resetFilters')
            ->assertSet('mode', 'map')
            ->assertDispatched(
                'gpx-routes-filtered',
                fn (string $event, array $params) => ! str_contains($params['url'], 'sector'),
            );
    }

    /** L'îlot écoute l'événement : sans ce câblage, le dispatch serveur ne servirait à rien. */
    public function test_the_map_island_listens_for_the_filter_event(): void
    {
        GpxRoute::factory()->create();

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->assertSee('gpx-routes-filtered', false)
            // Et surtout PAS de x-effect : il resterait figé sur l'URL du montage (régression).
            ->assertDontSee('x-effect', false);
    }

    /** Changer de mode ne doit pas rejouer la pagination : ce n'est pas un changement de filtre. */
    public function test_switching_mode_preserves_the_loaded_window(): void
    {
        GpxRoute::factory()->count(GpxRouteLibrary::PER_PAGE + 5)->create();

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('loadMore')
            ->assertSet('perPage', GpxRouteLibrary::PER_PAGE * 2)
            ->call('setMode', 'map')
            ->call('setMode', 'list')
            ->assertSet('perPage', GpxRouteLibrary::PER_PAGE * 2);
    }

    /** Le compte de la carte ne peut pas coller à celui de la liste : l'écart doit être annoncé. */
    public function test_map_mode_announces_routes_without_a_trace(): void
    {
        GpxRoute::factory()->create();
        GpxRoute::factory()->create(['polyline' => null]);

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->assertSet('mode', 'map')
            ->assertSee('sans données de tracé');
    }

    public function test_no_notice_when_every_route_is_mappable(): void
    {
        GpxRoute::factory()->count(2)->create();

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->assertDontSee('sans données de tracé');
    }

    /**
     * Verrou d'interaction (2026-08-02), même dispositif que la fiche parcours : 62vh de carte
     * captureraient le scroll de la page, filtres compris. Le test couvre le câblage Blade→Alpine,
     * pas le comportement Leaflet.
     */
    public function test_the_overview_map_is_lockable(): void
    {
        GpxRoute::factory()->create();

        Livewire::actingAs($this->member())->test(GpxRouteLibrary::class)
            ->call('setMode', 'map')
            ->assertSee('lockable: true', false)
            ->assertSee('Toucher pour interagir')
            ->assertSee('Déverrouiller la carte');
    }
}
