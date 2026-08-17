<?php

namespace Tests\Feature;

use App\Livewire\Admin\Journal;
use App\Models\AuditLog;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Journaux J6.7 — câblage Livewire : rendu, filtres, autocomplete, drawer, export, garde admin (§4.18.5).
class JournalUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auditLog(User $actor): AuditLog
    {
        $s = Session::create(['kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->subDay(), 'duration_min' => 60, 'created_by' => $actor->id]);

        return AuditLog::create(['action' => 'override_quota', 'actor_id' => $actor->id, 'actor_role' => 'coach',
            'session_id' => $s->id, 'motif' => 'Licence renouvelée', 'created_at' => Carbon::now()]);
    }

    public function test_renders_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->auditLog(User::factory()->coach()->create(['first_name' => 'Élise', 'last_name' => 'Dubois']));

        Livewire::actingAs($admin)
            ->test(Journal::class)
            ->assertOk()
            ->assertSee('Journaux')
            ->assertSee('override_quota')
            ->assertSee('Élise Dubois');
    }

    public function test_non_admin_is_forbidden(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Journal::class)
            ->assertForbidden();
    }

    public function test_source_and_period_settable_with_guards(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->assertSet('source', 'all')
            ->assertSet('period', '30d')
            ->call('setSource', 'audit')->assertSet('source', 'audit')
            ->call('setSource', 'bidon')->assertSet('source', 'audit') // invalide ignoré
            ->call('setPeriod', 'season')->assertSet('period', 'season')
            ->call('setPeriod', 'bidon')->assertSet('period', 'season');
    }

    public function test_toggle_action_filter(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->call('toggleAction', 'override_quota')->assertSet('actions', ['override_quota'])
            ->call('toggleAction', 'cancel_session')->assertSet('actions', ['override_quota', 'cancel_session'])
            ->call('toggleAction', 'override_quota')->assertSet('actions', ['cancel_session']); // re-clic retire
    }

    public function test_select_actor_resolves_label(): void
    {
        $actor = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Vidal']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->call('selectActor', $actor->id)
            ->assertSet('actorId', $actor->id)
            ->assertSet('actorLabel', 'Camille Vidal')
            ->call('clearActor')
            ->assertSet('actorId', null);
    }

    public function test_show_detail_opens_drawer(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $log = $this->auditLog($coach);

        Livewire::actingAs($admin)
            ->test(Journal::class)
            ->assertSet('detail', null)
            ->call('showDetail', 'audit', $log->id)
            ->assertSee('Licence renouvelée') // motif complet dans le drawer
            // Liens contextuels : acteur → fiche membre, séance → fiche séance.
            ->assertSeeHtml(route('admin.members.show', $coach->id))
            ->assertSeeHtml(route('sessions.show', $log->session_id))
            ->call('closeDetail')
            ->assertSet('detail', null);
    }

    public function test_detail_does_not_link_anonymized_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $log = $this->auditLog($coach);
        // Compte anonymisé (tombstone) : le nom scrubé reste affiché, mais sans lien mort vers sa fiche.
        $coach->forceFill(['anonymized_at' => now()])->save();

        Livewire::actingAs($admin)
            ->test(Journal::class)
            ->call('showDetail', 'audit', $log->id)
            ->assertDontSeeHtml(route('admin.members.show', $coach->id));
    }

    public function test_export_returns_xlsx_download(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->call('export')
            ->assertFileDownloaded('journaux-2026-06-20.xlsx');
    }

    public function test_load_more_widens_window_and_filters_reset_it(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->assertSet('perPage', 25)
            ->call('loadMore')->assertSet('perPage', 50)
            ->call('loadMore')->assertSet('perPage', 75)
            ->call('setSource', 'audit')->assertSet('perPage', 25); // tout changement de filtre rembobine la fenêtre
    }

    public function test_reset_filters_clears_everything(): void
    {
        $actor = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Vidal']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Journal::class)
            ->call('setSource', 'audit')
            ->call('setPeriod', 'season')
            ->call('selectActor', $actor->id)
            ->call('toggleAction', 'override_quota')
            ->call('loadMore')
            ->call('resetFilters')
            ->assertSet('source', 'all')
            ->assertSet('period', '30d')
            ->assertSet('actorId', null)
            ->assertSet('actorLabel', '')
            ->assertSet('actions', [])
            ->assertSet('targetType', null)
            ->assertSet('perPage', 25);
    }

    public function test_filters_flow_through_to_results_and_export(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $s = Session::create(['kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->subDay(), 'duration_min' => 60, 'created_by' => $coach->id]);
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);

        // total rendu = 1 sous le filtre prouve que filters() est appliqué ; export() réutilise
        // le même filters() → l'export hérite donc des mêmes filtres (téléchargement toujours servi).
        Livewire::actingAs($admin)
            ->test(Journal::class)
            ->call('setSource', 'audit')
            ->call('toggleAction', 'override_quota')
            ->assertViewHas('total', 1)
            ->call('export')
            ->assertFileDownloaded('journaux-2026-06-20.xlsx');
    }
}
