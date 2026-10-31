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

    public function test_non_admin_is_forbidden(): void
    {
        Livewire::actingAs(User::factory()->create()) // athlète simple
            ->test(Dashboard::class)
            ->assertForbidden();
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
