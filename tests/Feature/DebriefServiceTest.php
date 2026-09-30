<?php

namespace Tests\Feature;

use App\Models\Debrief;
use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\DebriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

// Débriefs de compétition (PRD §4.12.5).
class DebriefServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DebriefService
    {
        return app(DebriefService::class);
    }

    private function competition(bool $started = true): Session
    {
        return Session::create([
            'kind' => 'competition', 'title' => 'Triathlon de Nantes',
            'start_at' => $started ? Carbon::now()->subDay() : Carbon::now()->addWeek(),
            'duration_min' => 120, 'capacity' => null,
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

    public function test_participant_publishes_after_start(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);

        $debrief = $this->service()->publish($s, $u, '**Super course** !');

        $this->assertSame($u->id, $debrief->author_id);
        $this->assertStringContainsString('Super course', $debrief->content_markdown);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'debrief_published', 'actor_id' => $u->id, 'session_id' => $s->id,
        ]);
    }

    public function test_cannot_publish_before_start(): void
    {
        $s = $this->competition(started: false);
        $u = $this->participant($s);

        $this->expectException(RuntimeException::class);
        $this->service()->publish($s, $u, 'trop tôt');
    }

    public function test_non_participant_cannot_publish(): void
    {
        $s = $this->competition();
        $stranger = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->service()->publish($s, $stranger, 'pas inscrit');
    }

    public function test_one_debrief_per_author(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);
        $this->service()->publish($s, $u, 'premier');

        $this->expectException(RuntimeException::class);
        $this->service()->publish($s, $u, 'deuxième');
    }

    public function test_publish_rejected_on_non_competition(): void
    {
        $s = Session::create([
            'kind' => 'training', 'title' => 'Natation', 'start_at' => Carbon::now()->subDay(),
            'duration_min' => 90, 'created_by' => User::factory()->coach()->create()->id,
        ]);
        $u = $this->participant($s);

        $this->expectException(RuntimeException::class);
        $this->service()->publish($s, $u, 'x');
    }

    public function test_author_and_admin_can_update(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);
        $debrief = $this->service()->publish($s, $u, 'v1');

        $this->service()->update($debrief, $u, 'v2 par auteur');
        $this->assertStringContainsString('v2 par auteur', $debrief->fresh()->content_markdown);

        $admin = User::factory()->admin()->create();
        $this->service()->update($debrief, $admin, 'v3 par admin');
        $this->assertStringContainsString('v3 par admin', $debrief->fresh()->content_markdown);
    }

    public function test_archive_then_restore(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);
        $admin = User::factory()->admin()->create();
        $debrief = $this->service()->publish($s, $u, 'à archiver');

        $this->service()->archive($debrief, $admin);
        $debrief->refresh();
        $this->assertTrue($debrief->isArchived());
        $this->assertSame($admin->id, $debrief->archived_by);
        $this->assertSame(0, Debrief::active()->count());

        $this->service()->restore($debrief, $admin);
        $this->assertFalse($debrief->fresh()->isArchived());
        $this->assertSame(1, Debrief::active()->count());
    }

    public function test_content_is_sanitized(): void
    {
        $s = $this->competition();
        $u = $this->participant($s);

        $debrief = $this->service()->publish($s, $u, "**ok**\n\n<script>alert(1)</script>");

        $this->assertStringNotContainsString('<script', $debrief->content_markdown);
    }

    // ── J8.5 : notif new_debrief aux co-participants ──

    public function test_publish_notifies_co_participants_excluding_author(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $other = $this->participant($s);

        $this->service()->publish($s, $author, 'Mon retour de course');

        // L'auteur n'est pas notifié ; l'autre participant l'est (push + email).
        $this->assertSame(0, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $author->id)->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $other->id)->count());
    }

    public function test_publish_emits_nothing_when_author_is_sole_participant(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);

        $this->service()->publish($s, $author, 'Seul au monde');

        $this->assertSame(0, NotificationOutbox::where('type', 'new_debrief')->count());
    }

    // ── Débrief annoncé au reste du club (club_debrief, §4.12.5) ──

    public function test_publish_notifies_the_rest_of_the_club_by_default(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $other = $this->participant($s);
        $membre = User::factory()->create();

        $this->service()->publish($s, $author, 'Mon retour de course');

        // Un non-participant reçoit club_debrief (push + email), sans réglage préalable.
        $this->assertSame(2, NotificationOutbox::where('type', 'club_debrief')
            ->where('user_id', $membre->id)->count());
        // Le participant ne le reçoit pas en double, l'auteur pas du tout.
        $this->assertSame(0, NotificationOutbox::where('type', 'club_debrief')
            ->whereIn('user_id', [$author->id, $other->id])->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $other->id)->count());
    }

    public function test_club_debrief_skips_waitlisted_only_when_participating(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $attente = User::factory()->create();
        Registration::create([
            'session_id' => $s->id, 'user_id' => $attente->id,
            'status' => 'waitlist', 'registered_at' => Carbon::now()->subWeek(),
        ]);

        $this->service()->publish($s, $author, 'Mon retour de course');

        // En liste d'attente, on n'a pas participé : c'est la ligne « autre compétition » qui joue.
        $this->assertSame(0, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $attente->id)->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'club_debrief')
            ->where('user_id', $attente->id)->count());
    }

    public function test_club_debrief_respects_its_own_opt_out(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $coupe = User::factory()->create();
        NotificationPreferences::create([
            'user_id' => $coupe->id,
            'matrix' => ['club_debrief' => ['push' => false, 'email' => false]],
            'paused' => false,
        ]);
        $temoin = User::factory()->create();

        $this->service()->publish($s, $author, 'Mon retour de course');

        $this->assertSame(0, NotificationOutbox::where('user_id', $coupe->id)->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'club_debrief')
            ->where('user_id', $temoin->id)->count());
    }

    public function test_opting_out_of_club_debrief_keeps_participant_debriefs(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $other = $this->participant($s);
        NotificationPreferences::create([
            'user_id' => $other->id,
            'matrix' => ['club_debrief' => ['push' => false, 'email' => false]],
            'paused' => false,
        ]);

        $this->service()->publish($s, $author, 'Mon retour de course');

        $this->assertSame(2, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $other->id)->count());
    }

    public function test_club_debrief_reaches_a_guardian_once_and_never_inactive_accounts(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $parent = User::factory()->create(['roles' => ['parent']]);
        User::factory()->minorP1()->count(2)->create(['guardian_id' => $parent->id]);
        $inactif = User::factory()->create(['is_active' => false]);
        $anonyme = User::factory()->create(['anonymized_at' => Carbon::now()]);

        $this->service()->publish($s, $author, 'Mon retour de course');

        // Une seule annonce pour le parent, pas une par enfant.
        $this->assertSame(2, NotificationOutbox::where('type', 'club_debrief')
            ->where('user_id', $parent->id)->count());
        $this->assertSame(0, NotificationOutbox::whereIn('user_id', [$inactif->id, $anonyme->id])->count());
    }

    public function test_guardian_of_a_participant_gets_new_debrief_not_club_debrief(): void
    {
        $s = $this->competition();
        $author = $this->participant($s);
        $parent = User::factory()->create(['roles' => ['parent']]);
        $enfant = User::factory()->minorP1()->create(['guardian_id' => $parent->id]);
        Registration::create([
            'session_id' => $s->id, 'user_id' => $enfant->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subWeek(),
        ]);

        $this->service()->publish($s, $author, 'Mon retour de course');

        $this->assertSame(2, NotificationOutbox::where('type', 'new_debrief')
            ->where('user_id', $parent->id)->count());
        $this->assertSame(0, NotificationOutbox::where('type', 'club_debrief')
            ->where('user_id', $parent->id)->count());
    }
}
