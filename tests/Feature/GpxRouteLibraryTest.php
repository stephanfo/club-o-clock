<?php

namespace Tests\Feature;

use App\Livewire\GpxRouteLibrary;
use App\Livewire\SessionForm;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bibliothèque de parcours (PRD §4.20, J10.B) — filtres, pagination, visibilité des archivés.
 */
class GpxRouteLibraryTest extends TestCase
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

    public function test_library_is_open_to_every_member(): void
    {
        $this->actingAs($this->athlete())
            ->get(route('gpx-routes.index'))
            ->assertOk()
            ->assertSeeLivewire(GpxRouteLibrary::class);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('gpx-routes.index'))->assertRedirect();
    }

    public function test_search_matches_name_and_description(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle de Chambord']);
        GpxRoute::factory()->create(['name' => 'Tour de Sologne', 'description' => 'Passage par Chambord']);
        GpxRoute::factory()->create(['name' => 'Bords de Loire', 'description' => 'Plat']);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('search', 'Chambord')
            ->assertViewHas('total', 2);
    }

    public function test_sector_and_discipline_filters_narrow_the_list(): void
    {
        // Pas de factory sur Discipline (catalogue seedé) : create() direct, comme CatalogueServiceTest.
        $velo = Discipline::create(['label' => 'Vélo']);
        $course = Discipline::create(['label' => 'Course']);

        GpxRoute::factory()->create(['sector' => 'NE', 'discipline_id' => $velo->id]);
        GpxRoute::factory()->create(['sector' => 'NE', 'discipline_id' => $course->id]);
        GpxRoute::factory()->create(['sector' => 'S', 'discipline_id' => $velo->id]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('sector', ['NE'])
            ->assertViewHas('total', 2)
            ->set('discipline', [(string) $velo->id])
            ->assertViewHas('total', 1);
    }

    /** Au sein d'un filtre les valeurs s'UNISSENT : « secteur NE ou S ». */
    public function test_several_values_of_one_filter_are_a_union(): void
    {
        GpxRoute::factory()->create(['sector' => 'NE']);
        GpxRoute::factory()->create(['sector' => 'S']);
        GpxRoute::factory()->create(['sector' => 'O']);

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        $component->set('sector', ['NE'])->assertViewHas('total', 1);
        $component->set('sector', ['NE', 'S'])->assertViewHas('total', 2);
        $component->set('sector', ['NE', 'S', 'O'])->assertViewHas('total', 3);
    }

    /** Entre filtres différents, elles se CROISENT : « (NE ou S) ET exigeant ». */
    public function test_different_filters_intersect(): void
    {
        // NE + exigeant (771/84.5 ≈ 9,1)
        GpxRoute::factory()->create(['sector' => 'NE', 'dplus_m' => 771, 'distance_km' => 84.5]);
        // S + roulant (215/54.7 ≈ 3,9) : exclu par le relief malgré un secteur coché
        GpxRoute::factory()->create(['sector' => 'S', 'dplus_m' => 215, 'distance_km' => 54.7]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('sector', ['NE', 'S'])
            ->assertViewHas('total', 2)
            ->set('grade', ['tough'])
            ->assertViewHas('total', 1);
    }

    /**
     * Le prérequis « métriques exploitables » du relief reste hors du OU : un parcours sans D+ ne
     * doit pas ressortir dès qu'une valeur est cochée.
     */
    public function test_routes_without_metrics_never_match_a_grade_filter(): void
    {
        GpxRoute::factory()->create(['dplus_m' => null]);
        GpxRoute::factory()->create(['dplus_m' => 771, 'distance_km' => 84.5]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('grade', ['rolling', 'hilly', 'tough'])
            ->assertViewHas('total', 1);
    }

    public function test_distance_bands_are_half_open_so_a_route_falls_in_exactly_one(): void
    {
        // 50,0 km est la borne entre « 40-50 » et « 50-60 » : il tombe dans la seconde seulement.
        GpxRoute::factory()->create(['distance_km' => 50.0]);

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        $component->set('distance', ['40-50'])->assertViewHas('total', 0);
        $component->set('distance', ['50-60'])->assertViewHas('total', 1);
    }

    /**
     * Tranches contiguës cochées → un seul intervalle, pas une chaîne de OR (applyDistance).
     * Le résultat doit être exactement celui de la plage fusionnée, bornes comprises.
     */
    public function test_contiguous_distance_bands_merge_into_one_range(): void
    {
        foreach ([45.0, 55.0, 65.0, 75.0, 95.0] as $km) {
            GpxRoute::factory()->create(['distance_km' => $km]);
        }

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        // 50-60 + 60-70 + 70-80 ⇒ [50, 80[ : 55, 65 et 75.
        $component->set('distance', ['50-60', '60-70', '70-80'])->assertViewHas('total', 3);
        // Tranches disjointes : l'union reste correcte (45 et 95).
        $component->set('distance', ['40-50', '90-100'])->assertViewHas('total', 2);
    }

    /** La dernière tranche est ouverte à droite : la fusion ne doit pas refermer l'intervalle. */
    public function test_merging_keeps_the_last_band_open_ended(): void
    {
        GpxRoute::factory()->create(['distance_km' => 95.0]);
        GpxRoute::factory()->create(['distance_km' => 250.0]);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('distance', ['90-100', '100+'])
            ->assertViewHas('total', 2);
    }

    /** Aucun trou, aucun recouvrement : chaque parcours tombe dans exactement une tranche. */
    public function test_distance_bands_partition_the_library(): void
    {
        foreach ([12.0, 40.0, 49.9, 55.0, 64.5, 73.6, 85.1, 95.0, 130.0] as $km) {
            GpxRoute::factory()->create(['distance_km' => $km]);
        }

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        $sum = 0;
        foreach (array_keys(GpxRouteLibrary::DISTANCE_BANDS) as $band) {
            $sum += $component->set('distance', [$band])->viewData('total');
        }

        $this->assertSame(GpxRoute::count(), $sum);
    }

    public function test_shape_and_grade_filters_use_the_model_scopes(): void
    {
        GpxRoute::factory()->create(['elongation' => 1.10, 'dplus_m' => 215, 'distance_km' => 54.7]);  // arrondi · roulant
        GpxRoute::factory()->elongated()->create(['dplus_m' => 771, 'distance_km' => 84.5]);           // étiré · exigeant

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        $component->set('shape', ['round'])->assertViewHas('total', 1);
        $component->set('shape', ['long'])->assertViewHas('total', 1);
        // Les deux formes cochées : l'union couvre tout le corpus.
        $component->set('shape', ['round', 'long'])->assertViewHas('total', 2);
        $component->set('shape', [])->set('grade', ['tough'])->assertViewHas('total', 1);
    }

    public function test_toggle_accumulates_then_removes_values(): void
    {
        GpxRoute::factory()->create(['sector' => 'NE']);
        GpxRoute::factory()->create(['sector' => 'S']);
        GpxRoute::factory()->create(['sector' => 'O']);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->call('toggle', 'sector', 'NE')
            ->assertSet('sector', ['NE'])
            ->assertViewHas('total', 1)
            // Un second secteur s'ajoute au lieu de remplacer le premier.
            ->call('toggle', 'sector', 'S')
            ->assertSet('sector', ['NE', 'S'])
            ->assertViewHas('total', 2)
            // Re-cliquer retire cette valeur-là, sans toucher aux autres.
            ->call('toggle', 'sector', 'NE')
            ->assertSet('sector', ['S'])
            ->assertViewHas('total', 1)
            // Vider la liste rend le filtre inactif.
            ->call('toggle', 'sector', 'S')
            ->assertSet('sector', [])
            ->assertViewHas('total', 3);
    }

    public function test_toggle_ignores_an_unknown_filter_name(): void
    {
        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->call('toggle', 'archived', 'oui')
            ->assertSet('archived', false);
    }

    /** isOn() pilote l'état visuel des chips : il doit suivre toggle() à la lettre. */
    public function test_is_on_reflects_the_selection(): void
    {
        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);

        $component->call('toggle', 'grade', 'tough')->call('toggle', 'grade', 'hilly');

        $this->assertTrue($component->instance()->isOn('grade', 'tough'));
        $this->assertTrue($component->instance()->isOn('grade', 'hilly'));
        $this->assertFalse($component->instance()->isOn('grade', 'rolling'));
        // Un filtre hors liste blanche n'est jamais « actif ».
        $this->assertFalse($component->instance()->isOn('archived', 'oui'));
    }

    public function test_archived_routes_are_hidden_by_default(): void
    {
        GpxRoute::factory()->create(['name' => 'Active']);
        GpxRoute::factory()->archived()->create(['name' => 'Archivée']);

        Livewire::actingAs($this->coach())
            ->test(GpxRouteLibrary::class)
            ->assertViewHas('total', 1)
            ->set('archived', true)
            ->assertViewHas('total', 2);
    }

    /** Un athlète ne gère pas la bibliothèque : forcer `archived` par l'URL ne doit rien révéler. */
    public function test_an_athlete_cannot_reveal_archived_routes_through_the_url(): void
    {
        GpxRoute::factory()->create();
        GpxRoute::factory()->archived()->create();

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class, ['archived' => true])
            ->assertViewHas('total', 1);
    }

    public function test_load_more_widens_the_window_and_filters_reset_it(): void
    {
        GpxRoute::factory()->count(GpxRouteLibrary::PER_PAGE + 5)->create(['sector' => 'NE']);

        $component = Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class);
        $this->assertCount(GpxRouteLibrary::PER_PAGE, $component->viewData('routes'));

        $component->call('loadMore');
        $this->assertCount(GpxRouteLibrary::PER_PAGE + 5, $component->viewData('routes'));

        // Changer un filtre doit ramener la fenêtre à sa taille initiale.
        $component->set('search', 'Boucle')->assertSet('perPage', GpxRouteLibrary::PER_PAGE);
    }

    public function test_create_button_is_reserved_to_coaches(): void
    {
        Livewire::actingAs($this->coach())->test(GpxRouteLibrary::class)->assertSee('Ajouter');
        Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class)->assertDontSee('Ajouter');
    }

    // ── Sélecteur de parcours dans le formulaire de séance (J10.B) ──────────────────────────

    public function test_a_coach_can_attach_an_existing_route_to_a_session(): void
    {
        $route = GpxRoute::factory()->create(['name' => 'Boucle Loire', 'distance_km' => 42.5]);

        Livewire::actingAs($this->coach())
            ->test(SessionForm::class)
            ->call('toggleRoutePicker')
            ->assertSet('showRoutePicker', true)
            ->call('pickRoute', $route->id)
            ->assertSet('route_id', $route->id)
            ->assertSet('showRoutePicker', false)
            // Les métriques du parcours choisi alimentent l'aperçu, sans re-parser quoi que ce soit.
            ->assertSet('gpxStats.distanceKm', '42.5');
    }

    public function test_picking_an_archived_or_missing_route_is_refused(): void
    {
        $archived = GpxRoute::factory()->archived()->create();

        Livewire::actingAs($this->coach())
            ->test(SessionForm::class)
            ->call('pickRoute', $archived->id)
            ->assertSet('route_id', null)
            ->call('pickRoute', 999999)
            ->assertSet('route_id', null);
    }

    public function test_detaching_keeps_the_route_in_the_library(): void
    {
        $route = GpxRoute::factory()->create();

        Livewire::actingAs($this->coach())
            ->test(SessionForm::class)
            ->call('pickRoute', $route->id)
            ->call('removeGpx')
            ->assertSet('route_id', null);

        // Détacher n'est pas supprimer : le parcours reste en bibliothèque (doc J10 §3).
        $this->assertDatabaseHas('gpx_routes', ['id' => $route->id, 'archived_at' => null]);
    }

    public function test_route_candidates_are_searchable_and_exclude_archived(): void
    {
        GpxRoute::factory()->create(['name' => 'Boucle de Chambord']);
        GpxRoute::factory()->create(['name' => 'Tour de Sologne']);
        GpxRoute::factory()->archived()->create(['name' => 'Chambord archivé']);

        $component = Livewire::actingAs($this->coach())->test(SessionForm::class);

        $this->assertCount(2, $component->instance()->routeCandidates());

        $component->set('routeSearch', 'Chambord');
        $names = $component->instance()->routeCandidates()->pluck('name');
        $this->assertSame(['Boucle de Chambord'], $names->all());
    }

    public function test_reset_filters_clears_every_criterion(): void
    {
        GpxRoute::factory()->count(3)->create(['sector' => 'NE']);

        Livewire::actingAs($this->athlete())
            ->test(GpxRouteLibrary::class)
            ->set('search', 'x')->set('sector', ['S', 'NE'])->set('shape', ['long'])->set('grade', ['tough'])
            ->call('resetFilters')
            ->assertSet('search', '')->assertSet('sector', [])->assertSet('shape', [])->assertSet('grade', [])
            ->assertViewHas('total', 3);
    }
}
