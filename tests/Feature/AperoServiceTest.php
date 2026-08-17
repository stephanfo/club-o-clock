<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AperoFlag;
use App\Models\Session;
use App\Models\User;
use App\Services\AperoService;
use App\Services\CoachRegistrationService;
use App\Services\RegistrationService;
use App\Services\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Flag « j'offre l'apéro » (PRD §4.14) : pose/retrait, 3 voies de cascade, park/restore.
class AperoServiceTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function apero(): AperoService
    {
        return app(AperoService::class);
    }

    private function registrations(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function makeSession(?int $capacity = 10, ?Carbon $startAt = null): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => $startAt ?? Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90, 'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
    }

    /** Inscrit un nouvel athlète comme `participating` et le renvoie. */
    private function participant(Session $s): User
    {
        $u = $this->athlete();
        $this->registrations()->register($s, $u, $u);

        return $u;
    }

    public function test_participant_can_flag_apero(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);

        $flag = $this->apero()->flag($s, $u, '  mon anniversaire ');

        $this->assertSame('mon anniversaire', $flag->motif);
        $this->assertNull($flag->parked_at);
        $this->assertTrue($s->activeAperoFlags()->where('user_id', $u->id)->exists());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'apero_flagged', 'actor_id' => $u->id, 'session_id' => $s->id,
        ]);
    }

    public function test_non_participant_cannot_flag(): void
    {
        $s = $this->makeSession(capacity: 1);
        $this->participant($s);                 // occupe l'unique place
        $waitlisted = $this->participant($s);   // bascule en waitlist

        $this->expectException(RuntimeException::class);
        $this->apero()->flag($s, $waitlisted);
    }

    public function test_flag_is_idempotent(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);

        $this->apero()->flag($s, $u);
        $this->apero()->flag($s, $u);

        $this->assertSame(1, AperoFlag::where('session_id', $s->id)->where('user_id', $u->id)->count());
    }

    public function test_flag_blocked_after_start(): void
    {
        $s = $this->makeSession(startAt: Carbon::now()->addDays(2));
        $u = $this->participant($s);
        $s->forceFill(['start_at' => Carbon::now()->subHour()])->save();

        $this->expectException(RuntimeException::class);
        $this->apero()->flag($s->fresh(), $u);
    }

    public function test_self_unflag_hard_deletes_and_logs(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $this->apero()->flag($s, $u);

        $this->apero()->unflag($s, $u, $u);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'apero_unflagged', 'actor_id' => $u->id, 'user_id' => $u->id,
        ]);
    }

    public function test_staff_can_moderate_others_flag(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $coach = User::factory()->coach()->create();
        $this->apero()->flag($s, $u);

        $this->apero()->unflag($s, $u, $coach);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'apero_unflagged', 'actor_id' => $coach->id, 'user_id' => $u->id,
        ]);
    }

    public function test_cascade_on_self_deregister(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $this->apero()->flag($s, $u);

        $this->registrations()->cancel($s, $u, $u);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
        // Voie 3 : trace système (actor null + flag).
        $this->assertSame(1, ActivityLog::where('action', 'apero_unflagged')
            ->where('session_id', $s->id)->where('actor_is_system', true)->count());
    }

    public function test_cascade_on_role_flip_to_coach(): void
    {
        $s = $this->makeSession();
        $coach = $this->categorize(User::factory()->athleteCoach()->create());
        $this->registrations()->register($s, $coach, $coach);
        $this->apero()->flag($s, $coach);

        app(CoachRegistrationService::class)->flipToCoach($s, $coach, $coach);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $coach->id]);
    }

    public function test_cascade_on_season_bulk_deactivation(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $this->apero()->flag($s, $u);
        $admin = User::factory()->admin()->create();

        app(SeasonService::class)->deactivateAllAthletes($admin);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
    }

    public function test_session_cancel_parks_then_restore_reactivates(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $this->apero()->flag($s, $u, 'podium');

        // Annulation → flag garé (inactif mais conservé avec son motif).
        $this->apero()->cascadeOnSessionCancel($s);
        $flag = AperoFlag::where('session_id', $s->id)->where('user_id', $u->id)->first();
        $this->assertNotNull($flag->parked_at);
        $this->assertFalse($s->activeAperoFlags()->where('user_id', $u->id)->exists());

        // Restauration → flag réactivé, motif conservé.
        $this->apero()->restoreOnSessionUncancel($s);
        $flag->refresh();
        $this->assertNull($flag->parked_at);
        $this->assertSame('podium', $flag->motif);
    }

    public function test_parked_flag_not_restored_if_registration_lost_meanwhile(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $this->apero()->flag($s, $u);

        $this->apero()->cascadeOnSessionCancel($s);
        // L'inscription est perdue pendant l'annulation (cancel système, ex. bascule de saison).
        $this->registrations()->cancelAsSystem($s, $u);

        $this->apero()->restoreOnSessionUncancel($s);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
    }

    public function test_motif_truncated_to_140(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);

        $flag = $this->apero()->flag($s, $u, str_repeat('a', 200));

        $this->assertSame(140, mb_strlen($flag->motif));
    }
}
