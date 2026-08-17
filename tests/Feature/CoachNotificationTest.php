<?php

namespace Tests\Feature;

use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\CoachRegistrationService;
use App\Services\TemplateGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// J8.5 — Producteurs d'encadrement (§4.11) : affectation (coach_assigned), arrivée/départ d'un
// co-encadrant (coach_registration), récap de génération de série (coach_template_recap).
class CoachNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function coachService(): CoachRegistrationService
    {
        return app(CoachRegistrationService::class);
    }

    private function training(): Session
    {
        return Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90, 'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    public function test_third_party_assignment_notifies_coach_and_co_coaches(): void
    {
        $s = $this->training();
        $admin = User::factory()->admin()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

        $this->coachService()->register($s, $coachA, $admin); // 1er coach, pas de co-coach
        NotificationOutbox::truncate();

        $this->coachService()->register($s, $coachB, $admin); // affecté par l'admin

        // coach_assigned au nouvel affecté B (push + email).
        $this->assertSame(2, NotificationOutbox::where('type', 'coach_assigned')
            ->where('user_id', $coachB->id)->count());
        // coach_registration au co-encadrant A déjà présent.
        $this->assertSame(2, NotificationOutbox::where('type', 'coach_registration')
            ->where('user_id', $coachA->id)->count());
    }

    public function test_self_registration_skips_self_assigned_but_notifies_co_coaches(): void
    {
        $s = $this->training();
        $admin = User::factory()->admin()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

        $this->coachService()->register($s, $coachA, $admin);
        NotificationOutbox::truncate();

        $this->coachService()->register($s, $coachB, $coachB); // s'inscrit lui-même

        // Pas de coach_assigned à soi-même (déjà au courant)…
        $this->assertSame(0, NotificationOutbox::where('type', 'coach_assigned')->count());
        // …mais A est prévenu de l'arrivée.
        $this->assertSame(2, NotificationOutbox::where('type', 'coach_registration')
            ->where('user_id', $coachA->id)->count());
    }

    public function test_unregister_notifies_remaining_coaches(): void
    {
        $s = $this->training();
        $admin = User::factory()->admin()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();

        $this->coachService()->register($s, $coachA, $admin);
        $this->coachService()->register($s, $coachB, $admin);
        NotificationOutbox::truncate();

        $this->coachService()->unregister($s, $coachB, $admin);

        // A (restant) est prévenu du départ ; B (parti) ne l'est pas.
        $this->assertSame(2, NotificationOutbox::where('type', 'coach_registration')
            ->where('user_id', $coachA->id)->count());
        $this->assertSame(0, NotificationOutbox::where('user_id', $coachB->id)->count());
    }

    public function test_template_generation_sends_one_recap_per_coach(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);
        $tpl->defaultCoaches()->attach($coach->id);

        $created = app(TemplateGenerationService::class)->generate($tpl, $admin);

        $this->assertCount(4, $created); // 4 lundis générés
        // Un seul récap (push + email) malgré 4 séances — pas 8 coach_assigned.
        $this->assertSame(2, NotificationOutbox::where('type', 'coach_template_recap')
            ->where('user_id', $coach->id)->count());
        $this->assertSame(0, NotificationOutbox::where('type', 'coach_assigned')->count());
    }
}
