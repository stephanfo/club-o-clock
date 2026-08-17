<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\QuotaTag;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// UI quota sur la fiche séance (PRD §4.10) : confirmation, déblocage coach, badge override.
class QuotaUiTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function svc(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function tag(int $max = 1): QuotaTag
    {
        return QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => $max]);
    }

    private function makeSession(QuotaTag $tag, ?int $capacity = 10, int $dayOffset = 0): Session
    {
        $base = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeek()->setTime(19, 0);

        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation '.$dayOffset,
            'start_at' => $base->copy()->addDays($dayOffset),
            'duration_min' => 60, 'capacity' => $capacity, 'quota_tag_id' => $tag->id,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5)
    }

    public function test_over_quota_shows_confirmation_then_confirms(): void
    {
        $tag = $this->tag(max: 1);
        $s1 = $this->makeSession($tag, dayOffset: 0);
        $s2 = $this->makeSession($tag, dayOffset: 2);
        $u = $this->athlete();
        $this->svc()->register($s1, $u, $u); // quota 1/1

        $component = Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s2])
            ->call('enroll')
            ->assertSet('confirmingQuota', true)
            ->assertSee('quota', false);

        // Confirmation → waitlist quota_exceeded.
        $component->call('enroll', true)->assertSet('confirmingQuota', false);
        $this->assertDatabaseHas('registrations', [
            'session_id' => $s2->id, 'user_id' => $u->id,
            'status' => 'waitlist', 'waitlist_reason' => 'quota_exceeded',
        ]);
    }

    public function test_reenroll_after_unenroll_does_not_leave_dialog(): void
    {
        // Quota large : inscription normale → désinscription → réinscription ne doit JAMAIS
        // rouvrir le dialog de confirmation quota (E1 : $confirmingQuota bien remis à false).
        $tag = $this->tag(max: 5);
        $s = $this->makeSession($tag, capacity: 10);
        $u = $this->athlete();

        $c = Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s]);
        $c->call('enroll')->assertSet('confirmingQuota', false);
        $c->call('unenroll')->assertSet('confirmingQuota', false);
        $c->call('enroll')->assertSet('confirmingQuota', false)->assertSee('Se désinscrire');
    }

    public function test_coach_fills_from_quota_exceeded(): void
    {
        $tag = $this->tag(max: 1);
        $s = $this->makeSession($tag, capacity: 5);
        $coach = User::factory()->coach()->create();

        // Un athlète au quota plein ailleurs → quota_exceeded sur s, capacité libre.
        $a = $this->athlete();
        $other = $this->makeSession($tag, dayOffset: 4);
        $this->svc()->register($other, $a, $a);
        $this->svc()->register($s, $a, $a, confirmQuota: true);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('fillQuota');

        $this->assertDatabaseHas('registrations', [
            'session_id' => $s->id, 'user_id' => $a->id, 'status' => 'participating',
        ]);
    }

    public function test_override_badge_visible_to_coach_only(): void
    {
        $tag = $this->tag(max: 1);
        $s = $this->makeSession($tag, capacity: 1);
        $coach = User::factory()->coach()->create();
        $taken = $this->athlete();
        $this->svc()->register($s, $taken, $taken);

        $forced = User::factory()->create(['first_name' => 'Forcee', 'last_name' => 'Override']);
        $this->svc()->overrideRegister($s, $forced, $coach, motif: 'cas');

        // Coach voit le badge override.
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('override', false);

        // Un autre athlète ne le voit pas.
        $athlete = $this->athlete();
        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertDontSee('override', false);
    }
}
