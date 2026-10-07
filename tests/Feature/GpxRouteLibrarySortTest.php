<?php

namespace Tests\Feature;

use App\Livewire\GpxRouteLibrary;
use App\Models\GpxRoute;
use App\Models\Location;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bibliothèque de parcours, #130 : filtres « Utilisé » et « Départ », tri, repli mobile des filtres.
 *
 * L'instant est figé au 15 octobre 2026, 12 h, heure club (Europe/Paris, saison ouverte le 1er sept.) :
 * les périodes « ce mois-ci », « 3 derniers mois » et « saison » en dépendent.
 */
class GpxRouteLibrarySortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'Europe/Paris'));
    }

    private function athlete(): User
    {
        return User::factory()->create(['roles' => ['athlete']]);
    }

    private function coach(): User
    {
        return User::factory()->coach()->create();
    }

    /** Séance rattachée au parcours. route_id et cancelled_at ne sont pas mass-assignables. */
    private function sessionOn(GpxRoute $route, string $start, bool $cancelled = false): Session
    {
        return Session::forceCreate([
            'kind' => 'training',
            'title' => 'Sortie club',
            'start_at' => Carbon::parse($start, 'Europe/Paris'),
            'duration_min' => 90,
            'route_id' => $route->id,
            'cancelled_at' => $cancelled ? now() : null,
        ]);
    }

    private function library(array $params = []): Testable
    {
        return Livewire::actingAs($this->athlete())->test(GpxRouteLibrary::class, $params);
    }

    /** @return list<string> noms des parcours affichés, dans l'ordre */
    private function names(Testable $component): array
    {
        return $component->viewData('routes')->pluck('name')->all();
    }

    /**
     * Corpus d'usage : A roulé ce mois-ci, B en septembre, C seulement sur une séance annulée,
     * D seulement planifié (séance à venir), E jamais rattaché.
     */
    private function usageCorpus(): void
    {
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'A']), '2026-10-03 09:00');
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'B']), '2026-09-20 09:00');
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'C']), '2026-10-05 09:00', cancelled: true);
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'D']), '2026-10-20 09:00');
        GpxRoute::factory()->create(['name' => 'E']);
    }

    // ── Filtre « Utilisé » ──────────────────────────────────────────────────────────────────

    public function test_used_periods_count_only_sessions_that_took_place(): void
    {
        $this->usageCorpus();
        $component = $this->library();

        $component->call('setUsed', 'month');
        $this->assertSame(['A'], $this->names($component));

        $component->call('setUsed', '3months');
        $this->assertSame(['A', 'B'], $this->names($component));

        $component->call('setUsed', 'season');
        $this->assertSame(['A', 'B'], $this->names($component));
    }

    /** Annulée ou seulement planifiée : la séance ne vaut pas utilisation. */
    public function test_never_used_includes_cancelled_and_planned_only_routes(): void
    {
        $this->usageCorpus();

        $component = $this->library()->call('setUsed', 'never');

        $this->assertSame(['C', 'D', 'E'], $this->names($component));
    }

    /**
     * Une séance SANS parcours (le cas courant) ne doit pas vider « jamais utilisé » : un NULL dans
     * la sous-requête d'un NOT IN rendrait la condition inconnue pour toutes les lignes.
     */
    public function test_never_used_survives_sessions_without_a_route(): void
    {
        GpxRoute::factory()->create(['name' => 'Orphelin']);
        Session::forceCreate([
            'kind' => 'training', 'title' => 'Piscine', 'duration_min' => 60,
            'start_at' => Carbon::parse('2026-10-01 19:00', 'Europe/Paris'),
        ]);

        $component = $this->library()->call('setUsed', 'never');

        $this->assertSame(['Orphelin'], $this->names($component));
    }

    /** La saison démarre au 1er du mois de bascule : une sortie d'août n'en fait pas partie. */
    public function test_season_starts_at_the_club_rollover_month(): void
    {
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Août']), '2026-08-30 09:00');
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Septembre']), '2026-09-01 08:00');

        $component = $this->library()->call('setUsed', 'season');
        $this->assertSame(['Septembre'], $this->names($component));

        // … mais elle tombe bien dans les 3 derniers mois (depuis le 15 juillet).
        $component->call('setUsed', '3months');
        $this->assertSame(['Août', 'Septembre'], $this->names($component));
    }

    public function test_custom_period_bounds_are_inclusive_days(): void
    {
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Début']), '2026-09-15 07:00');
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Fin']), '2026-09-30 21:00');
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Hors']), '2026-10-01 09:00');

        $component = $this->library()
            ->call('setUsed', 'custom')
            ->set('usedFrom', '2026-09-15')
            ->set('usedTo', '2026-09-30');

        $this->assertSame(['Début', 'Fin'], $this->names($component));

        // Borne haute vide : période ouverte jusqu'à aujourd'hui.
        $component->set('usedTo', '');
        $this->assertSame(['Début', 'Fin', 'Hors'], $this->names($component));
    }

    /** Valeurs forgées dans l'URL : ignorées, jamais d'erreur ni d'écran vide. */
    public function test_forged_used_values_are_ignored(): void
    {
        $this->usageCorpus();

        $this->library(['used' => 'demain'])->assertOk()->assertViewHas('total', 5);

        // 31 février : la borne invalide est écartée, la période reste ouverte de ce côté.
        $component = $this->library(['used' => 'custom', 'usedFrom' => '2026-02-31', 'usedTo' => '2026-09-30']);
        $this->assertSame(['B'], $this->names($component));

        // Aucune borne valide : la période personnalisée ne filtre rien.
        $this->library(['used' => 'custom', 'usedFrom' => '<script>'])->assertOk()->assertViewHas('total', 5);
    }

    /** Choisir « Période… » ne masque rien tant qu'aucune date n'est saisie, et ne compte pas comme filtre. */
    public function test_custom_period_without_dates_filters_nothing(): void
    {
        $this->usageCorpus();

        $component = $this->library()->call('setUsed', 'custom');

        $component->assertViewHas('total', 5);
        $this->assertSame(0, $component->instance()->activeFilterCount());

        // Contrôle positif : une borne posée filtre et compte.
        $component->set('usedFrom', '2026-10-01');
        $this->assertSame(['A'], $this->names($component));
        $this->assertSame(1, $component->instance()->activeFilterCount());
    }

    /** Des bornes inversées (saisie clavier, URL) sont permutées au lieu de rendre une liste vide. */
    public function test_reversed_custom_bounds_are_swapped(): void
    {
        $this->usageCorpus();

        $component = $this->library(['used' => 'custom', 'usedFrom' => '2026-09-30', 'usedTo' => '2026-09-01']);

        $this->assertSame(['B'], $this->names($component));
    }

    public function test_set_used_is_a_single_choice_toggle(): void
    {
        $this->library()
            ->call('setUsed', 'month')->assertSet('used', 'month')
            // Une autre chip remplace la première : pas d'union.
            ->call('setUsed', 'season')->assertSet('used', 'season')
            // Second clic sur la chip active : filtre inactif.
            ->call('setUsed', 'season')->assertSet('used', '')
            // Clé inconnue : rien ne bouge.
            ->call('setUsed', 'month')->call('setUsed', 'hier')->assertSet('used', 'month');
    }

    /** Quitter « Période… » efface ses bornes, qui sinon resteraient invisibles dans l'URL. */
    public function test_leaving_the_custom_period_clears_its_bounds(): void
    {
        $this->library()
            ->call('setUsed', 'custom')
            ->set('usedFrom', '2026-09-01')->set('usedTo', '2026-09-30')
            ->call('setUsed', 'month')
            ->assertSet('usedFrom', '')
            ->assertSet('usedTo', '');
    }

    public function test_custom_period_shows_date_fields_only_when_chosen(): void
    {
        $this->library()
            ->assertSee('Période…')
            ->assertDontSee('lib-used-from')
            ->call('setUsed', 'custom')
            ->assertSee('lib-used-from');
    }

    // ── Filtre « Départ » ───────────────────────────────────────────────────────────────────

    public function test_location_filter_narrows_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $stade = Location::create(['name' => 'Stade', 'created_by' => $admin->id]);
        $port = Location::create(['name' => 'Port', 'created_by' => $admin->id]);

        GpxRoute::factory()->create(['name' => 'Du stade', 'start_location_id' => $stade->id]);
        GpxRoute::factory()->create(['name' => 'Du port', 'start_location_id' => $port->id]);
        GpxRoute::factory()->create(['name' => 'Sans lieu']);

        $component = $this->library()->call('toggle', 'location', (string) $stade->id);
        $this->assertSame(['Du stade'], $this->names($component));

        // Union au sein du filtre, comme les autres chips.
        $component->call('toggle', 'location', (string) $port->id);
        $this->assertSame(['Du port', 'Du stade'], $this->names($component));
    }

    /** Ne proposer que des lieux qui renvoient quelque chose : pas toute la table `locations`. */
    public function test_only_locations_of_visible_routes_are_offered(): void
    {
        $admin = User::factory()->admin()->create();
        $used = Location::create(['name' => 'Stade', 'created_by' => $admin->id]);
        $unused = Location::create(['name' => 'Piscine', 'created_by' => $admin->id]);
        $archivedOnly = Location::create(['name' => 'Gymnase', 'created_by' => $admin->id]);

        GpxRoute::factory()->create(['start_location_id' => $used->id]);
        GpxRoute::factory()->archived()->create(['start_location_id' => $archivedOnly->id]);

        $offered = fn (Testable $c) => $c->viewData('locations')->pluck('name')->all();

        // Un athlète ne voit pas les archivés : leur lieu n'est pas proposé non plus.
        $this->assertSame(['Stade'], $offered($this->library()));
        $this->assertNotContains($unused->name, $offered($this->library()));

        // Un coach qui affiche les archivés voit leur lieu apparaître.
        $coachView = Livewire::actingAs($this->coach())->test(GpxRouteLibrary::class)->set('archived', true);
        $this->assertSame(['Gymnase', 'Stade'], $offered($coachView));
    }

    public function test_location_block_is_hidden_when_no_route_has_a_start_location(): void
    {
        GpxRoute::factory()->create();

        $this->library()->assertSee('Utilisé')->assertDontSee('Départ');
    }

    // ── Tri ─────────────────────────────────────────────────────────────────────────────────

    public function test_default_sort_is_by_name(): void
    {
        GpxRoute::factory()->create(['name' => 'Zénith']);
        GpxRoute::factory()->create(['name' => 'Abbaye']);

        $component = $this->library()->assertSet('sort', 'name');
        $this->assertSame(['Abbaye', 'Zénith'], $this->names($component));
    }

    /** Les parcours sans la donnée triée vont en fin de liste, dans LES DEUX sens. */
    public function test_distance_sort_keeps_missing_values_last_both_ways(): void
    {
        GpxRoute::factory()->create(['name' => 'Long', 'distance_km' => 85.0]);
        GpxRoute::factory()->create(['name' => 'Court', 'distance_km' => 40.0]);
        GpxRoute::factory()->create(['name' => 'Inconnu', 'distance_km' => null]);

        $component = $this->library()->set('sort', 'distance');
        $this->assertSame(['Court', 'Long', 'Inconnu'], $this->names($component));

        $component->set('sort', 'distance-desc');
        $this->assertSame(['Long', 'Court', 'Inconnu'], $this->names($component));
    }

    /**
     * Relief = D+ PAR KILOMÈTRE, pas D+ total : « Long roulant » a plus de D+ que « Court raide »
     * mais un indice bien plus faible.
     */
    public function test_grade_sort_uses_dplus_per_km(): void
    {
        GpxRoute::factory()->create(['name' => 'Long roulant', 'distance_km' => 100.0, 'dplus_m' => 500]); // 5,0
        GpxRoute::factory()->create(['name' => 'Court raide', 'distance_km' => 40.0, 'dplus_m' => 360]);   // 9,0
        GpxRoute::factory()->create(['name' => 'Moyen', 'distance_km' => 50.0, 'dplus_m' => 350]);          // 7,0
        GpxRoute::factory()->create(['name' => 'Sans D+', 'dplus_m' => null]);
        GpxRoute::factory()->create(['name' => 'Zéro km', 'distance_km' => 0, 'dplus_m' => 100]);

        $component = $this->library()->set('sort', 'grade');
        $this->assertSame(['Long roulant', 'Moyen', 'Court raide', 'Sans D+', 'Zéro km'], $this->names($component));

        $component->set('sort', 'grade-desc');
        $this->assertSame(['Court raide', 'Moyen', 'Long roulant', 'Sans D+', 'Zéro km'], $this->names($component));
    }

    public function test_recent_sort_puts_the_newest_routes_first(): void
    {
        $this->travelTo(Carbon::parse('2026-01-10'));
        GpxRoute::factory()->create(['name' => 'Ancien']);
        $this->travelTo(Carbon::parse('2026-06-10'));
        GpxRoute::factory()->create(['name' => 'Récent']);
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'Europe/Paris'));

        $component = $this->library()->set('sort', 'recent');
        $this->assertSame(['Récent', 'Ancien'], $this->names($component));
    }

    public function test_last_used_sort_ignores_cancelled_and_planned_sessions(): void
    {
        $this->usageCorpus();

        $component = $this->library()->set('sort', 'last-used');

        // A (3 oct.) puis B (20 sept.) ; C, D, E jamais utilisés → fin de liste, par nom.
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names($component));
    }

    public function test_most_used_sort_counts_held_sessions_only(): void
    {
        $classique = GpxRoute::factory()->create(['name' => 'Classique']);
        $this->sessionOn($classique, '2026-09-06 09:00');
        $this->sessionOn($classique, '2026-09-13 09:00');
        $this->sessionOn($classique, '2026-09-20 09:00');

        // Deux séances tenues, plus une annulée et une planifiée qui ne doivent pas la faire passer devant.
        $gonfle = GpxRoute::factory()->create(['name' => 'Gonflé']);
        $this->sessionOn($gonfle, '2026-09-07 09:00');
        $this->sessionOn($gonfle, '2026-09-14 09:00');
        $this->sessionOn($gonfle, '2026-09-21 09:00', cancelled: true);
        $this->sessionOn($gonfle, '2026-10-25 09:00');
        $this->sessionOn($gonfle, '2026-11-01 09:00');

        GpxRoute::factory()->create(['name' => 'Inédit']);

        $component = $this->library()->set('sort', 'most-used');

        $this->assertSame(['Classique', 'Gonflé', 'Inédit'], $this->names($component));
        $this->assertSame([3, 2, 0], $component->viewData('routes')->pluck('uses_count')->all());
    }

    /** Un tri forgé dans l'URL retombe sur le nom, au montage comme en cours de route. */
    public function test_forged_sort_falls_back_to_name(): void
    {
        GpxRoute::factory()->create(['name' => 'B']);
        GpxRoute::factory()->create(['name' => 'A']);

        $component = $this->library(['sort' => 'distance_km; DROP TABLE'])->assertSet('sort', 'name');
        $this->assertSame(['A', 'B'], $this->names($component));

        $component->set('sort', 'n_importe_quoi')->assertSet('sort', 'name');
    }

    public function test_changing_the_sort_resets_the_load_more_window(): void
    {
        GpxRoute::factory()->count(GpxRouteLibrary::PER_PAGE + 2)->create();

        $this->library()
            ->call('loadMore')
            ->set('sort', 'distance')
            ->assertSet('perPage', GpxRouteLibrary::PER_PAGE);
    }

    /** Réinitialiser vide les filtres, pas le tri : le tri est une manière de lire, pas un filtre. */
    public function test_reset_filters_clears_new_filters_but_keeps_the_sort(): void
    {
        $this->library()
            ->set('sort', 'grade')
            ->call('setUsed', 'custom')->set('usedFrom', '2026-09-01')
            ->call('toggle', 'location', '3')
            ->call('resetFilters')
            ->assertSet('used', '')->assertSet('usedFrom', '')->assertSet('location', [])
            ->assertSet('sort', 'grade');
    }

    // ── Affichage ───────────────────────────────────────────────────────────────────────────

    public function test_cards_show_the_grade_value_next_to_its_label(): void
    {
        // 320 / 42,5 = 7,5 m/km → Exigeant
        GpxRoute::factory()->create();

        $this->library()->assertSee('Exigeant · 7,5 m/km');
    }

    public function test_cards_show_the_usage_data_of_usage_sorts_only(): void
    {
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Roulé']), '2026-10-03 09:00');
        GpxRoute::factory()->create(['name' => 'Jamais']);

        // Tri par nom : aucune donnée d'usage sur les cartes.
        $this->library()->assertDontSee('utilisé le')->assertDontSee('jamais utilisé');

        $this->library(['sort' => 'last-used'])->assertSee('utilisé le sam. 3 oct.')->assertSee('jamais utilisé');
        $this->library(['sort' => 'most-used'])->assertSee('1 séance')->assertSee('jamais utilisé');
    }

    /**
     * Avec une période « Utilisé » active, l'usage se compte sur cette période : « Cette saison » +
     * « Les plus utilisés » classe la saison, pas l'historique, et la carte affiche le compte de la saison.
     */
    public function test_usage_sorts_count_within_the_active_period(): void
    {
        // Saison 2026-2027 ouverte en septembre : Ancien domine l'historique, Saison domine la saison.
        $ancien = GpxRoute::factory()->create(['name' => 'Ancien']);
        foreach (['2026-03-01', '2026-04-01', '2026-05-01', '2026-10-01'] as $day) {
            $this->sessionOn($ancien, "$day 09:00");
        }
        $saison = GpxRoute::factory()->create(['name' => 'Saison']);
        foreach (['2026-09-20', '2026-09-27'] as $day) {
            $this->sessionOn($saison, "$day 09:00");
        }

        // Contrôle : sans période, le total historique fait foi.
        $this->assertSame(['Ancien', 'Saison'], $this->names($this->library(['sort' => 'most-used'])));

        $component = $this->library(['sort' => 'most-used', 'used' => 'season']);
        $this->assertSame(['Saison', 'Ancien'], $this->names($component));
        $component->assertSee('2 séances')->assertSee('1 séance')->assertDontSee('4 séances');

        // Dernière utilisation bornée par la fin d'une période libre.
        $libre = $this->library(['sort' => 'last-used', 'used' => 'custom', 'usedFrom' => '2026-09-01', 'usedTo' => '2026-09-30']);
        $this->assertSame(['Saison'], $this->names($libre));
        $libre->assertSee('utilisé le dim. 27 sept.');
    }

    /** Les sous-requêtes d'usage ne sont posées que pour le tri qui les lit (coût par ligne sur le mutualisé). */
    public function test_usage_subqueries_only_run_for_usage_sorts(): void
    {
        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Roulé']), '2026-10-03 09:00');

        $byName = $this->library()->viewData('routes')->first()->getAttributes();
        $this->assertArrayNotHasKey('last_used_at', $byName);
        $this->assertArrayNotHasKey('uses_count', $byName);

        $lastUsed = $this->library(['sort' => 'last-used'])->viewData('routes')->first()->getAttributes();
        $this->assertArrayHasKey('last_used_at', $lastUsed);
        $this->assertArrayNotHasKey('uses_count', $lastUsed);

        $mostUsed = $this->library(['sort' => 'most-used'])->viewData('routes')->first()->getAttributes();
        $this->assertArrayHasKey('uses_count', $mostUsed);
        $this->assertArrayNotHasKey('last_used_at', $mostUsed);
    }

    /** Une utilisation d'une autre année porte son année, sinon « 3 oct. » serait ambigu. */
    public function test_last_used_label_adds_the_year_only_when_it_differs(): void
    {
        $this->assertSame('utilisé le sam. 3 oct.', GpxRouteLibrary::usedOnLabel('2026-10-03 07:00:00'));
        $this->assertSame('utilisé le 12 mars 2025', GpxRouteLibrary::usedOnLabel('2025-03-12 07:00:00'));
        $this->assertSame('jamais utilisé', GpxRouteLibrary::usedOnLabel(null));
    }

    // ── Repli mobile ────────────────────────────────────────────────────────────────────────

    /** Le bouton « Filtres (n) » compte les BLOCS actifs ; la recherche, toujours visible, n'y entre pas. */
    public function test_filter_button_counts_active_filter_blocks(): void
    {
        $component = $this->library()->set('search', 'Loire');
        $this->assertSame(0, $component->instance()->activeFilterCount());
        $component->assertSeeHtml('Filtres');

        $component->call('toggle', 'sector', 'N')->call('toggle', 'sector', 'NE')->call('setUsed', 'month');
        $this->assertSame(2, $component->instance()->activeFilterCount());
        $component->assertSee('Filtres (2)');
    }

    /** `archived` forcé par l'URL chez un athlète est ignoré par la requête : il ne compte pas non plus. */
    public function test_archived_flag_counts_only_for_managers(): void
    {
        $this->assertSame(0, $this->library(['archived' => true])->instance()->activeFilterCount());

        $coachView = Livewire::actingAs($this->coach())->test(GpxRouteLibrary::class, ['archived' => true]);
        $this->assertSame(1, $coachView->instance()->activeFilterCount());
    }

    // ── Carte ───────────────────────────────────────────────────────────────────────────────

    /** La carte réapplique les nouveaux filtres : elle ne peut pas diverger de la liste. */
    public function test_traces_endpoint_applies_the_location_and_used_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $stade = Location::create(['name' => 'Stade', 'created_by' => $admin->id]);

        $this->sessionOn(GpxRoute::factory()->create(['name' => 'Roulé du stade', 'start_location_id' => $stade->id]), '2026-10-03 09:00');
        GpxRoute::factory()->create(['name' => 'Jamais du stade', 'start_location_id' => $stade->id]);
        GpxRoute::factory()->create(['name' => 'Ailleurs']);

        $member = $this->athlete();

        $this->actingAs($member)->get(route('gpx-routes.traces', ['location' => [$stade->id]]))
            ->assertOk()->assertJsonCount(2, 'routes');

        $this->actingAs($member)->get(route('gpx-routes.traces', ['location' => [$stade->id], 'used' => 'never']))
            ->assertOk()->assertJsonCount(1, 'routes')->assertJsonPath('routes.0.name', 'Jamais du stade');

        $this->actingAs($member)->get(route('gpx-routes.traces', ['used' => 'custom', 'usedFrom' => '2026-10-01', 'usedTo' => '2026-10-31']))
            ->assertOk()->assertJsonCount(1, 'routes')->assertJsonPath('routes.0.name', 'Roulé du stade');

        // Paramètre forgé en tableau là où un scalaire est attendu : ignoré, pas d'erreur 500.
        $this->actingAs($member)->get(route('gpx-routes.traces', ['used' => ['never']]))
            ->assertOk()->assertJsonCount(3, 'routes');
    }

    /** L'URL de départ de l'îlot carte porte les nouveaux filtres ; les bornes, seulement en « Période… ». */
    public function test_traces_url_carries_the_new_filters(): void
    {
        $component = $this->library()
            ->call('toggle', 'location', '7')
            ->call('setUsed', 'custom')->set('usedFrom', '2026-09-01');

        $url = urldecode($component->viewData('tracesUrl'));
        $this->assertStringContainsString('location[0]=7', $url);
        $this->assertStringContainsString('used=custom', $url);
        $this->assertStringContainsString('usedFrom=2026-09-01', $url);

        $component->call('setUsed', 'never');
        $url = urldecode($component->viewData('tracesUrl'));
        $this->assertStringContainsString('used=never', $url);
        $this->assertStringNotContainsString('usedFrom', $url);
    }
}
