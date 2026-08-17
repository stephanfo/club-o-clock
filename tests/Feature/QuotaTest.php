<?php

namespace Tests\Feature;

use App\Models\QuotaTag;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Quota fair-share + mécanismes A/B/C + override (PRD §4.10).
class QuotaTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function svc(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function tag(int $maxPerWeek = 1): QuotaTag
    {
        return QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => $maxPerWeek]);
    }

    /** Séance taguée, dans une même semaine future (lun→dim), pour rester avant le début. */
    private function makeSession(QuotaTag $tag, ?int $capacity = 10, int $dayOffset = 0): Session
    {
        // Lundi de la semaine prochaine : toutes les séances du test sont futures et dans la même semaine.
        $base = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeek()->setTime(19, 0);

        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation '.$dayOffset,
            'start_at' => $base->copy()->addDays($dayOffset),
            'duration_min' => 60, 'capacity' => $capacity, 'quota_tag_id' => $tag->id,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5)
    }

    public function test_under_quota_registers_participating(): void
    {
        $tag = $this->tag(maxPerWeek: 2);
        $s = $this->makeSession($tag);
        $u = $this->athlete();

        $reg = $this->svc()->register($s, $u, $u);

        $this->assertSame('participating', $reg->status);
    }

    public function test_over_quota_needs_confirmation(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s1 = $this->makeSession($tag, dayOffset: 0);
        $s2 = $this->makeSession($tag, dayOffset: 2);
        $u = $this->athlete();

        $this->svc()->register($s1, $u, $u); // 1/1 → quota plein

        // Sans confirmation : exception dédiée.
        try {
            $this->svc()->register($s2, $u, $u);
            $this->fail('Attendu QUOTA_NEEDS_CONFIRM.');
        } catch (RuntimeException $e) {
            $this->assertSame(RegistrationService::QUOTA_NEEDS_CONFIRM, $e->getMessage());
        }

        // Avec confirmation : waitlist quota_exceeded.
        $reg = $this->svc()->register($s2, $u, $u, confirmQuota: true);
        $this->assertSame('waitlist', $reg->status);
        $this->assertSame('quota_exceeded', $reg->waitlist_reason);
    }

    public function test_untagged_session_ignores_quota(): void
    {
        $u = $this->athlete();
        foreach (range(0, 3) as $d) {
            $s = $this->targetCategory(Session::create([
                'kind' => 'training', 'title' => 'Renfo '.$d,
                'start_at' => Carbon::now()->addDays($d + 1)->setTime(19, 0),
                'duration_min' => 60, 'capacity' => 10, 'quota_tag_id' => null,
                'created_by' => User::factory()->coach()->create()->id,
            ]));
            $this->assertSame('participating', $this->svc()->register($s, $u, $u)->status);
        }
    }

    public function test_mechanism_b_case_a_promotes_when_room(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s1 = $this->makeSession($tag, capacity: 10, dayOffset: 0);
        $s2 = $this->makeSession($tag, capacity: 10, dayOffset: 2);
        $u = $this->athlete();

        $this->svc()->register($s1, $u, $u);                       // participating, quota 1/1
        $r2 = $this->svc()->register($s2, $u, $u, confirmQuota: true); // quota_exceeded (capacité libre)
        $this->assertSame('quota_exceeded', $r2->fresh()->waitlist_reason);

        // Libère le quota en se désinscrivant de s1 → mécanisme B cas (a) : s2 confirmé.
        $this->svc()->cancel($s1, $u, $u);

        $this->assertSame('participating', $r2->fresh()->status);
        $this->assertNull($r2->fresh()->waitlist_reason);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auto_promoted_self_quota', 'user_id' => $u->id, 'resulting_status' => 'participating',
        ]);
    }

    public function test_mechanism_b_case_b_migrates_to_capacity_when_full(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s1 = $this->makeSession($tag, capacity: 10, dayOffset: 0);
        $s2 = $this->makeSession($tag, capacity: 1, dayOffset: 2);
        $u = $this->athlete();
        $other = $this->athlete();

        $this->svc()->register($s2, $other, $other);               // s2 plein (capacité 1)
        $this->svc()->register($s1, $u, $u);                       // u : participating s1, quota 1/1
        $r2 = $this->svc()->register($s2, $u, $u, confirmQuota: true); // u : quota_exceeded sur s2
        $registeredAt = $r2->fresh()->registered_at;

        $this->svc()->cancel($s1, $u, $u); // libère quota ; s2 plein → cas (b) migration capacity

        $r2 = $r2->fresh();
        $this->assertSame('waitlist', $r2->status);
        $this->assertSame('capacity', $r2->waitlist_reason);
        $this->assertEquals($registeredAt, $r2->registered_at); // FIFO conservé
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auto_promoted_self_quota', 'resulting_status' => 'waitlist_capacity',
        ]);
    }

    // Invariant §4.10.4 : une place libérée ne desserre le quota QUE d'une unité. Avec deux séances en
    // file quota_exceeded (capacité libre) et max 1/sem, la désinscription n'en promeut qu'UNE (la plus
    // ancienne, FIFO) — la seconde reste bloquée car l'athlète repasse aussitôt au quota. Couvre le
    // `break` de releaseOwnQuota que les cas (a)/(b) à séance unique ne testent pas.
    public function test_mechanism_b_releases_only_one_seat_per_freed_unit(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s1 = $this->makeSession($tag, capacity: 10, dayOffset: 0);
        $s2 = $this->makeSession($tag, capacity: 10, dayOffset: 2);
        $s3 = $this->makeSession($tag, capacity: 10, dayOffset: 4);
        $u = $this->athlete();

        $this->svc()->register($s1, $u, $u);                       // participating, quota 1/1
        $r2 = $this->svc()->register($s2, $u, $u, confirmQuota: true); // quota_exceeded (le + ancien)
        $r3 = $this->svc()->register($s3, $u, $u, confirmQuota: true); // quota_exceeded (le + récent)

        $this->svc()->cancel($s1, $u, $u); // libère 1 unité de quota

        // Exactement une promotion : s2 (FIFO) passe participating, s3 reste quota_exceeded.
        $this->assertSame('participating', $r2->fresh()->status);
        $this->assertSame('waitlist', $r3->fresh()->status);
        $this->assertSame('quota_exceeded', $r3->fresh()->waitlist_reason);
    }

    public function test_mechanism_c_fills_from_quota_exceeded(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s = $this->makeSession($tag, capacity: 2);
        $coach = User::factory()->coach()->create();

        // 2 athlètes participating (remplissent la capacité via leur 1er créneau), puis quota_exceeded sur s.
        $a = $this->athlete();
        $b = $this->athlete();
        $other1 = $this->makeSession($tag, dayOffset: 4);
        $this->svc()->register($other1, $a, $a);
        $this->svc()->register($other1, $b, $b);
        $ra = $this->svc()->register($s, $a, $a, confirmQuota: true);
        $rb = $this->svc()->register($s, $b, $b, confirmQuota: true);

        // s a 0 participating, 2 places libres, file capacity vide → mécanisme C promeut les 2.
        $promoted = $this->svc()->fillFromQuotaExceeded($s, $coach, motif: 'créneau ouvert');

        $this->assertSame(2, $promoted);
        $this->assertSame('participating', $ra->fresh()->status);
        $this->assertSame('participating', $rb->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'promote_quota_exceeded', 'target_id' => $a->id, 'motif' => 'créneau ouvert',
        ]);
    }

    public function test_mechanism_c_blocked_when_capacity_queue_not_empty(): void
    {
        $tag = $this->tag(maxPerWeek: 5);
        $s = $this->makeSession($tag, capacity: 1);
        $coach = User::factory()->coach()->create();

        $a = $this->athlete();
        $b = $this->athlete();
        $this->svc()->register($s, $a, $a);  // participating (capacité 1)
        $this->svc()->register($s, $b, $b);  // waitlist capacity (quota non atteint)

        $this->expectException(RuntimeException::class);
        $this->svc()->fillFromQuotaExceeded($s, $coach);
    }

    public function test_override_forces_participating_over_quota_and_capacity(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s = $this->makeSession($tag, capacity: 1);
        $coach = User::factory()->coach()->create();

        $taken = $this->athlete();
        $this->svc()->register($s, $taken, $taken); // capacité pleine

        $suspended = User::factory()->create(['athlete_access_suspended' => true]);
        $reg = $this->svc()->overrideRegister($s, $suspended, $coach, motif: 'cas particulier');

        $this->assertSame('participating', $reg->status);
        $this->assertSame($coach->id, $reg->override_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'override_quota', 'target_id' => $suspended->id, 'motif' => 'cas particulier',
        ]);
    }

    public function test_session_cancellation_frees_quota_and_triggers_mechanism_b(): void
    {
        $tag = $this->tag(maxPerWeek: 1);
        $s1 = $this->makeSession($tag, capacity: 10, dayOffset: 0);
        $s2 = $this->makeSession($tag, capacity: 10, dayOffset: 2);
        $u = $this->athlete();

        $this->svc()->register($s1, $u, $u);                          // participating s1, quota 1/1
        $r2 = $this->svc()->register($s2, $u, $u, confirmQuota: true); // quota_exceeded s2
        $this->assertSame('quota_exceeded', $r2->fresh()->waitlist_reason);

        // Annulation de s1 : flag posé + cascade → mécanisme B promeut s2.
        $s1->forceFill(['cancelled_at' => Carbon::now()])->save();
        $this->svc()->onSessionCancelled($s1);

        $this->assertSame('participating', $r2->fresh()->status);
        // L'inscription sur s1 est conservée (réversibilité de la restauration).
        $this->assertSame('participating', $s1->registrations()->where('user_id', $u->id)->first()->status);
    }
}
