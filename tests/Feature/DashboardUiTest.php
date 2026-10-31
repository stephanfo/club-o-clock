<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\ActivityLog;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Dashboard bureau J6.6 — câblage Livewire : rendu, filtres, export XLSX, garde admin (PRD §4.16).
class DashboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12));
        Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renders_for_admin(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Dashboard admin')
            ->assertSee('taux de remplissage')
            ->assertSee('Activité coachs');
    }

    public function test_shows_weekly_attendance_and_key_figures_without_overrides(): void
    {
        $session = Session::create([
            'kind' => 'training', 'title' => 'Natation', 'start_at' => Carbon::now()->subDays(3),
            'duration_min' => 60, 'created_by' => User::factory()->coach()->create()->id,
        ]);
        Registration::create(['session_id' => $session->id, 'user_id' => User::factory()->create()->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subDays(5)]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Fréquentation · par semaine')
            ->assertSee('Détail par semaine')
            ->assertSee('class="chart"', false)
            ->assertSee('pratiquants')
            ->assertSee('compétiteurs')
            ->assertDontSee('overrides coach')
            ->assertDontSee('Évolution des inscriptions');
    }

    public function test_shows_slots_and_unregistrations_instead_of_top_sessions(): void
    {
        $session = Session::create([
            'kind' => 'training', 'title' => 'Natation', 'discipline_id' => Discipline::first()->id,
            'start_at' => Carbon::now()->subDays(3), 'duration_min' => 60, 'created_by' => User::factory()->coach()->create()->id,
        ]);
        Registration::create(['session_id' => $session->id, 'user_id' => User::factory()->create()->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subDays(5)]);
        ActivityLog::create(['action' => 'desinscription', 'session_id' => $session->id, 'created_at' => Carbon::now()->subDays(3)->subHours(2)]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Créneaux · fréquentation')
            ->assertSee('Séances ponctuelles')
            ->assertSee('class="tbl tbl-cartes"', false)
            ->assertSee('moins de 24 h avant le début')
            ->assertDontSee('Top séances');
    }

    public function test_slots_and_unregistrations_say_so_when_empty(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Créneaux · fréquentation')
            ->assertSee('Aucune désinscription sur la période.')
            ->assertDontSee('Séances ponctuelles');
    }

    public function test_lists_sessions_to_staff_with_link_instead_of_counter(): void
    {
        $session = Session::create([
            'kind' => 'training', 'title' => 'Vélo route', 'discipline_id' => Discipline::first()->id,
            'start_at' => Carbon::create(2026, 6, 23, 16, 30), 'duration_min' => 90, 'created_by' => User::factory()->coach()->create()->id,
        ]);
        Session::create(['kind' => 'training', 'title' => 'Natation', 'discipline_id' => Discipline::first()->id,
            'start_at' => Carbon::now()->subDays(3), 'duration_min' => 60, 'created_by' => $session->created_by]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Séances à encadrer')
            ->assertSee('mar. 23 juin · 18:30') // heure du club
            ->assertSee(route('sessions.show', $session), false)
            ->assertSee('Vélo route')
            ->assertSee('Séances tenues encadrées')
            ->assertDontSee('voir le planning');
    }

    public function test_sessions_to_staff_says_so_when_all_are_staffed(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Toutes les séances des 15 prochains jours sont encadrées.')
            ->assertDontSee('Séances tenues encadrées');
    }

    public function test_weekly_block_says_so_when_nothing_was_held(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Aucune séance tenue sur la période.')
            ->assertDontSee('class="chart"', false);
    }

    public function test_shows_dropouts_with_guardian_and_member_links(): void
    {
        $guardian = User::factory()->create(['roles' => ['parent'], 'first_name' => 'Florence', 'last_name' => 'Martin']);
        $ward = User::factory()->minorP1()->create(['first_name' => 'Lucie', 'last_name' => 'Martin', 'guardian_id' => $guardian->id]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Décrochage')
            ->assertSee('sans séance depuis plus de 21 jours')
            ->assertSee('Jamais venus · 1')
            ->assertSee('Lucie Martin')
            ->assertSee('garant : Florence Martin')
            ->assertSee(route('admin.members.show', $ward), false)
            ->assertSee('jamais connecté')
            ->assertSee('Effectifs · par catégorie');
    }

    public function test_shows_competitions_usage_and_journal_link(): void
    {
        $race = Session::create(['kind' => 'competition', 'title' => 'Duathlon de la Plage',
            'start_at' => Carbon::now()->subDays(4), 'duration_min' => 120, 'created_by' => User::factory()->coach()->create()->id]);
        Registration::create(['session_id' => $race->id, 'user_id' => User::factory()->create()->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subDays(9)]);

        Livewire::actingAs(User::factory()->admin()->create(['last_login_at' => Carbon::now()]))
            ->test(Dashboard::class)
            ->assertSee('Duathlon de la Plage')
            ->assertSee('1</b> compétiteur distinct', false)
            ->assertSee(route('sessions.show', $race), false)
            ->assertSee("Usage de l'app", false)
            ->assertSee('Connectés dans les 30 derniers jours')
            ->assertSee(route('admin.journal'), false)
            ->assertDontSee('Aucune compétition sur la période.');
    }

    public function test_competitions_say_so_when_empty(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Aucune compétition sur la période.');
    }

    public function test_non_admin_is_forbidden(): void
    {
        Livewire::actingAs(User::factory()->create()) // athlète simple
            ->test(Dashboard::class)
            ->assertForbidden();
    }

    /** Vue coach (§4.16.3) : les blocs autorisés, sans décrochage, effectifs, usage ni export. */
    public function test_coach_sees_reduced_view_without_admin_blocks(): void
    {
        User::factory()->minorP1()->create(['first_name' => 'Lucie', 'last_name' => 'Martin']); // jamais venue
        $race = Session::create(['kind' => 'competition', 'title' => 'Duathlon de la Plage',
            'start_at' => Carbon::now()->subDays(4), 'duration_min' => 120, 'created_by' => User::factory()->coach()->create()->id]);
        Registration::create(['session_id' => $race->id, 'user_id' => User::factory()->create()->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subDays(9)]);

        Livewire::actingAs(User::factory()->coach()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Pilotage')
            ->assertSee('Séances à encadrer')
            ->assertSee('Fréquentation · par semaine')
            ->assertSee('Créneaux · fréquentation')
            ->assertSee('Mon activité')
            ->assertDontSee('Dashboard admin')
            ->assertDontSee('Décrochage')
            ->assertDontSee('Lucie Martin')
            ->assertDontSee('Effectifs · par catégorie')
            ->assertDontSee('Duathlon de la Plage')
            ->assertDontSee("Usage de l'app", false)
            ->assertDontSee('Activité coachs')
            ->assertDontSee('wire:click="export"', false)
            ->assertDontSee(route('admin.journal'), false);
    }

    /** L'export est gardé côté serveur : le bouton absent ne suffit pas, l'appel forcé est refusé. */
    public function test_coach_cannot_export(): void
    {
        Livewire::actingAs(User::factory()->coach()->create())
            ->test(Dashboard::class)
            ->call('export')
            ->assertForbidden();
    }

    /** Sa propre ligne d'activité, pas celle des autres coachs. */
    public function test_coach_sees_only_own_activity_row(): void
    {
        $me = User::factory()->coach()->create(['first_name' => 'Camille', 'last_name' => 'Durand']);
        $other = User::factory()->coach()->create(['first_name' => 'Bruno', 'last_name' => 'Lefort']);
        foreach ([$me, $other] as $coach) {
            $s = Session::create(['kind' => 'training', 'title' => 'Natation', 'discipline_id' => Discipline::first()->id,
                'start_at' => Carbon::now()->subDays(3), 'duration_min' => 60, 'created_by' => $coach->id]);
            $s->coaches()->attach($coach->id);
        }

        Livewire::actingAs($me)->test(Dashboard::class)
            ->assertSee('Camille Durand')
            ->assertDontSee('Bruno Lefort')
            ->assertDontSee('Séances tenues encadrées');

        // Contrôle : l'admin, lui, voit les deux.
        Livewire::actingAs(User::factory()->admin()->create())->test(Dashboard::class)
            ->assertSee('Camille Durand')
            ->assertSee('Bruno Lefort');
    }

    public function test_coach_reaches_the_screen_by_url_but_athlete_does_not(): void
    {
        $this->actingAs(User::factory()->coach()->create())->get(route('admin.dashboard'))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_period_and_filters_are_settable(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSet('period', 'season')
            ->call('setPeriod', '90d')
            ->assertSet('period', '90d')
            ->call('setPeriod', 'bidon') // valeur invalide ignorée
            ->assertSet('period', '90d');
    }

    public function test_export_returns_xlsx_download(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->call('export')
            ->assertFileDownloaded('stats-bureau-2026-06-20.xlsx');
    }
}
