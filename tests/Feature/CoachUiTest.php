<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Qualification;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// UI encadrement sur la fiche séance (PRD §4.11) : inscription coach, bascule role-flip, qualifs agrégées.
class CoachUiTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function makeSession(?int $capacity = 10): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90, 'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
    }

    public function test_coach_self_registers_from_fiche(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('registerCoachSelf');

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
    }

    public function test_unregister_last_coach_opens_then_confirms_dialog(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $s->coaches()->attach($coach->id);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('unregisterCoach', $coach->id)
            ->assertSet('lastCoachConfirm.coach_id', $coach->id) // dialog ouvert, pas encore retiré
            ->call('unregisterCoach', $coach->id, true)
            ->assertSet('lastCoachConfirm', null);

        $this->assertFalse($s->coaches()->whereKey($coach->id)->exists());
    }

    public function test_self_flip_to_athlete_opens_confirm_then_enrolls(): void
    {
        $s = $this->makeSession(capacity: 5);
        $c1 = $this->categorize(User::factory()->athleteCoach()->create());
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        Livewire::actingAs($c1)->test(SessionShow::class, ['session' => $s])
            ->call('flipToAthlete', $c1->id)
            ->assertSet('flipConfirm.dir', 'to_athlete')
            ->call('flipToAthlete', $c1->id, true)
            ->assertSet('flipConfirm', null);

        $this->assertFalse($s->coaches()->whereKey($c1->id)->exists());
        $this->assertSame('participating', $c1->registrations()->where('session_id', $s->id)->first()->status);
    }

    public function test_admin_picks_and_registers_other_coach(): void
    {
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->call('openCoachPicker')
            ->assertSet('pickingCoach', true)
            ->assertSee($coach->fullName())
            ->call('registerCoach', $coach->id)
            ->assertSet('pickingCoach', false);

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
    }

    public function test_aggregated_qualifications_render_deduplicated(): void
    {
        $s = $this->makeSession();
        $bf5 = Qualification::create(['label' => 'BF5', 'code' => 'BF5', 'sort_order' => 1]);
        $bnssa = Qualification::create(['label' => 'BNSSA', 'code' => 'BNSSA', 'sort_order' => 2]);

        $c1 = User::factory()->coach()->create();
        $c2 = User::factory()->coach()->create();
        // Les deux portent BF5 (dédup attendue), seul c1 porte BNSSA.
        $c1->qualifications()->attach([$bf5->id => ['attributed_at' => now()], $bnssa->id => ['attributed_at' => now()]]);
        $c2->qualifications()->attach([$bf5->id => ['attributed_at' => now()]]);
        $s->coaches()->attach([$c1->id, $c2->id]);

        $viewer = User::factory()->create();
        Livewire::actingAs($viewer)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Qualifications disponibles')
            ->assertSee('BF5')
            ->assertSee('BNSSA')
            // Liste nominative en noms complets (§4.11.4).
            ->assertSee($c1->fullName());
    }

    public function test_no_coach_banner_shown_on_empty_training(): void
    {
        $s = $this->makeSession();
        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Pas de coach inscrit');
    }
}
