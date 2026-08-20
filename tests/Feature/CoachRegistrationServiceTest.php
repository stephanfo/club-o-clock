<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\CoachRegistrationService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Encadrement coach + role-flip J4 (PRD §4.11). Inscription coach 3 voies, garde dernier coach,
// bascule athlète ↔ coach 4 cas + cascade quota A/B.
class CoachRegistrationServiceTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée un mercredi : les offsets relatifs (+1/+3 j) restent dans la MÊME
        // semaine quota (lun→dim), sinon le test quota cassait certains jours (straddle dim/lun).
        Carbon::setTestNow(Carbon::create(2026, 6, 17, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): CoachRegistrationService
    {
        return app(CoachRegistrationService::class);
    }

    private function makeSession(?int $capacity = null, ?QuotaTag $tag = null, int $dayOffset = 2): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training',
            'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays($dayOffset)->setTime(19, 0),
            'duration_min' => 90,
            'capacity' => $capacity,
            'quota_tag_id' => $tag?->id,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
    }

    // ─────────────────────────  Inscription coach (§4.11.2)  ─────────────────────────

    public function test_coach_can_self_register_as_coach(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();

        $this->service()->register($s, $coach, $coach);

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'coach_registered', 'actor_id' => $coach->id, 'user_id' => $coach->id,
        ]);
    }

    public function test_third_party_can_register_another_coach(): void
    {
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $this->service()->register($s, $coach, $admin);

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
        // actorId ≠ userId quand l'inscription est faite par un tiers (§4.11.2).
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'coach_registered', 'actor_id' => $admin->id, 'user_id' => $coach->id,
        ]);
    }

    public function test_register_is_idempotent(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();

        $this->service()->register($s, $coach, $coach);
        $this->service()->register($s, $coach, $coach);

        $this->assertSame(1, $s->coaches()->count());
        // Idempotent : pas de second log coach_registered au 2e appel.
        $this->assertSame(1, ActivityLog::where('action', 'coach_registered')->where('user_id', $coach->id)->count());
    }

    public function test_cannot_register_non_coach(): void
    {
        $s = $this->makeSession();
        $athlete = $this->athlete(); // rôle athlete par défaut

        $this->expectException(RuntimeException::class);
        $this->service()->register($s, $athlete, $athlete);
    }

    public function test_cannot_register_coach_on_non_training(): void
    {
        $s = Session::create([
            'kind' => 'competition', 'title' => 'Triathlon S',
            'start_at' => Carbon::now()->addDays(5), 'duration_min' => 120,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $coach = User::factory()->coach()->create();

        $this->expectException(RuntimeException::class);
        $this->service()->register($s, $coach, $coach);
    }

    public function test_cannot_register_coach_on_started_session(): void
    {
        $s = $this->makeSession(dayOffset: 2);
        $s->forceFill(['start_at' => Carbon::now()->subHour()])->save(); // déjà commencée
        $coach = User::factory()->coach()->create();

        $this->expectException(RuntimeException::class);
        $this->service()->register($s, $coach, $coach);
    }

    public function test_cannot_register_coach_on_cancelled_session(): void
    {
        $s = $this->makeSession();
        $s->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $s->created_by])->save();
        $coach = User::factory()->coach()->create();

        $this->expectException(RuntimeException::class);
        $this->service()->register($s, $coach, $coach);
    }

    // ─────────────────────────  Désinscription + dernier coach (§4.11.2)  ─────────────────────────

    public function test_unregister_removes_coach(): void
    {
        $s = $this->makeSession();
        $c1 = User::factory()->coach()->create();
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        $this->service()->unregister($s, $c1, $c1);

        $this->assertFalse($s->coaches()->whereKey($c1->id)->exists());
        $this->assertTrue($s->coaches()->whereKey($c2->id)->exists());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'coach_unregistered', 'user_id' => $c1->id,
        ]);
    }

    public function test_removing_last_coach_requires_confirmation(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $s->coaches()->attach($coach->id);

        try {
            $this->service()->unregister($s, $coach, $coach);
            $this->fail('Attendu : LAST_COACH_NEEDS_CONFIRM');
        } catch (RuntimeException $e) {
            $this->assertSame(CoachRegistrationService::LAST_COACH_NEEDS_CONFIRM, $e->getMessage());
        }

        // Toujours encadrant tant que non confirmé.
        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());

        // Avec confirmation : retrait autorisé (responsabilité humaine prime).
        $this->service()->unregister($s, $coach, $coach, confirmLastCoach: true);
        $this->assertFalse($s->coaches()->whereKey($coach->id)->exists());
    }

    // ─────────────────────────  Bascule athlète → coach (§4.11.5 cas 1/4)  ─────────────────────────

    public function test_flip_athlete_to_coach_cancels_registration_and_promotes_capacity(): void
    {
        $s = $this->makeSession(capacity: 1);
        $coach = $this->categorize(User::factory()->athleteCoach()->create());
        $waiter = $this->athlete();

        // Le coach est d'abord inscrit comme athlète (occupe l'unique place) ; un 2e athlète en waitlist.
        app(RegistrationService::class)->register($s, $coach, $coach);
        app(RegistrationService::class)->register($s, $waiter, $waiter);
        $this->assertSame('waitlist', $waiter->registrations()->first()->status);

        // Bascule athlète → coach : libère la place → mécanisme A promeut le waiter.
        $this->service()->flipToCoach($s, $coach, $coach);

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
        $this->assertSame('cancelled', Registration::where('user_id', $coach->id)->where('session_id', $s->id)->first()->status);
        $this->assertSame('participating', Registration::where('user_id', $waiter->id)->where('session_id', $s->id)->first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_changed', 'target_id' => $coach->id, 'motif' => 'athlete_to_coach']);
    }

    public function test_third_party_can_flip_athlete_to_coach(): void
    {
        $s = $this->makeSession(capacity: 5);
        $admin = User::factory()->admin()->create();
        $coach = $this->categorize(User::factory()->athleteCoach()->create());

        // Le coach est d'abord inscrit comme athlète, puis basculé par le bureau (cas 4 — tiers).
        app(RegistrationService::class)->register($s, $coach, $coach);
        $this->service()->flipToCoach($s, $coach, $admin);

        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
        $this->assertSame('cancelled', Registration::where('user_id', $coach->id)->where('session_id', $s->id)->first()->status);
        // actorId = admin (tiers), targetId = coach basculé (§4.11.5 cas 4).
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role_changed', 'actor_id' => $admin->id, 'target_id' => $coach->id, 'motif' => 'athlete_to_coach',
        ]);
    }

    // ─────────────────────────  Bascule coach → athlète (§4.11.5 cas 2/3)  ─────────────────────────

    public function test_flip_coach_to_athlete_enrolls_as_participating(): void
    {
        $s = $this->makeSession(capacity: 5);
        $c1 = $this->categorize(User::factory()->athleteCoach()->create());
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        $status = $this->service()->flipToAthlete($s, $c1, $c1);

        $this->assertSame('participating', $status);
        $this->assertFalse($s->coaches()->whereKey($c1->id)->exists());
        $this->assertSame('participating', Registration::where('user_id', $c1->id)->where('session_id', $s->id)->first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_changed', 'target_id' => $c1->id, 'motif' => 'coach_to_athlete']);
    }

    // C1 — la bascule est aussi un retrait d'encadrement : elle doit apparaître au journal
    // d'activité, comme unregister(). Symétrique de flipToCoach, qui émet bien coach_registered.
    public function test_flip_coach_to_athlete_logs_coach_unregistered(): void
    {
        $s = $this->makeSession(capacity: 5);
        $c1 = $this->categorize(User::factory()->athleteCoach()->create());
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        $this->service()->flipToAthlete($s, $c1, $c1);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'coach_unregistered',
            'user_id' => $c1->id,
            'session_id' => $s->id,
        ]);
    }

    // C2 — l'encadrement est une notion training (§4.11) : la bascule s'aligne sur register() et
    // flipToCoach, qui refusent déjà les autres kind.
    public function test_flip_coach_to_athlete_refused_on_competition(): void
    {
        $s = $this->makeSession(capacity: 5);
        $s->forceFill(['kind' => 'competition'])->save();
        $c1 = $this->categorize(User::factory()->athleteCoach()->create());
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("L'inscription coach ne s'applique qu'aux entraînements.");
        $this->service()->flipToAthlete($s->fresh(), $c1, $c1);
    }

    public function test_flip_last_coach_to_athlete_requires_confirmation(): void
    {
        $s = $this->makeSession(capacity: 5);
        $coach = $this->categorize(User::factory()->athleteCoach()->create());
        $s->coaches()->attach($coach->id);

        try {
            $this->service()->flipToAthlete($s, $coach, $coach);
            $this->fail('Attendu : LAST_COACH_NEEDS_CONFIRM');
        } catch (RuntimeException $e) {
            $this->assertSame(CoachRegistrationService::LAST_COACH_NEEDS_CONFIRM, $e->getMessage());
        }
        // Inchangé tant que non confirmé.
        $this->assertTrue($s->coaches()->whereKey($coach->id)->exists());
        $this->assertNull(Registration::where('user_id', $coach->id)->first());

        $status = $this->service()->flipToAthlete($s, $coach, $coach, confirmLastCoach: true);
        $this->assertSame('participating', $status);
        $this->assertFalse($s->coaches()->whereKey($coach->id)->exists());
    }

    public function test_flip_coach_to_athlete_over_quota_needs_confirm(): void
    {
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $other = $this->makeSession(capacity: 5, tag: $tag, dayOffset: 1);
        $s = $this->makeSession(capacity: 5, tag: $tag, dayOffset: 3);

        $c1 = $this->categorize(User::factory()->athleteCoach()->create());
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        // c1 consomme déjà son unique quota de la semaine sur l'autre séance.
        app(RegistrationService::class)->register($other, $c1, $c1);

        // Bascule coach → athlète sur $s : l'inscription athlète déborde le quota → confirmation requise.
        try {
            $this->service()->flipToAthlete($s, $c1, $c1);
            $this->fail('Attendu : QUOTA_NEEDS_CONFIRM');
        } catch (RuntimeException $e) {
            $this->assertSame(RegistrationService::QUOTA_NEEDS_CONFIRM, $e->getMessage());
        }

        // Avec confirmation quota : inscrit en waitlist quota_exceeded.
        $status = $this->service()->flipToAthlete($s, $c1, $c1, confirmQuota: true);
        $this->assertSame('waitlist', $status);
        $reg = Registration::where('user_id', $c1->id)->where('session_id', $s->id)->first();
        $this->assertSame('quota_exceeded', $reg->waitlist_reason);
    }
}
