<?php

namespace Tests\Feature;

use App\Models\Debrief;
use App\Models\NotificationOutbox;
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
}
