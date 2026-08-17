<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\DebriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// UI débriefs sur la fiche compétition (PRD §4.12.5).
class DebriefUiTest extends TestCase
{
    use RefreshDatabase;

    private function competition(): Session
    {
        return Session::create([
            'kind' => 'competition', 'title' => 'Triathlon de Nantes',
            'start_at' => Carbon::now()->subDay(), 'duration_min' => 120,
            'created_by' => User::factory()->admin()->create()->id,
        ]);
    }

    private function participant(Session $s): User
    {
        $u = User::factory()->create();
        Registration::create([
            'session_id' => $s->id, 'user_id' => $u->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subWeek(),
        ]);

        return $u;
    }

    public function test_participant_publishes_via_editor(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->call('openDebrief')
            ->assertSet('debriefOpen', true)
            ->set('debriefMarkdown', 'Ma course était top')
            ->call('saveDebrief')
            ->assertSet('debriefOpen', false);

        $this->assertDatabaseHas('debriefs', ['session_id' => $s->id, 'author_id' => $u->id]);
    }

    public function test_admin_archives_and_restores(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);
        $admin = User::factory()->admin()->create();
        $debrief = app(DebriefService::class)->publish($s, $u, 'à modérer');

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->call('confirmArchiveDebrief', $debrief->id)
            ->assertSet('debriefArchiveId', $debrief->id)
            ->call('archiveDebrief', $debrief->id)
            ->assertSet('debriefArchiveId', null);
        $this->assertTrue($debrief->fresh()->isArchived());

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->call('restoreDebrief', $debrief->id);
        $this->assertFalse($debrief->fresh()->isArchived());
    }

    public function test_other_member_cannot_edit_a_debrief(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $other = $this->participant($s);
        $debrief = app(DebriefService::class)->publish($s, $author, 'mon retour');

        Livewire::actingAs($other)->test(SessionShow::class, ['session' => $s])
            ->call('openDebrief', $debrief->id)
            ->assertForbidden();
    }

    public function test_non_participant_has_no_cta(): void
    {
        $s = $this->competition();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)->test(SessionShow::class, ['session' => $s])
            ->assertSet('debriefOpen', false)
            ->assertDontSee('Rédiger mon débrief');
    }
}
