<?php

namespace Tests\Feature;

use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// État lu/non-lu des alertes (revue UX 2026-07-11) : badge de non-lus + marquage en bloc
// à l'ouverture de la page Alertes.
class AlertsReadStateTest extends TestCase
{
    use RefreshDatabase;

    private function sentPush(User $u, array $attrs = []): NotificationOutbox
    {
        return NotificationOutbox::create(array_merge([
            'type' => 'session_cancelled', 'channel' => 'push', 'payload' => [],
            'user_id' => $u->id, 'status' => 'sent', 'sent_at' => Carbon::now(),
        ], $attrs));
    }

    public function test_unread_count_covers_only_visible_alerts(): void
    {
        $u = User::factory()->create();
        $this->sentPush($u);                                          // comptée
        $this->sentPush($u, ['read_at' => Carbon::now()]);            // lue → non comptée
        $this->sentPush($u, ['channel' => 'email']);                  // pas une alerte push
        $this->sentPush($u, ['status' => 'pending', 'sent_at' => null]); // pas encore envoyée
        $this->sentPush(User::factory()->create());                   // autre utilisateur

        $this->assertSame(1, NotificationOutbox::unreadCountFor($u->id));
    }

    public function test_opening_alerts_page_marks_all_read(): void
    {
        $u = User::factory()->create();
        $this->sentPush($u);
        $this->sentPush($u);

        $this->assertSame(2, NotificationOutbox::unreadCountFor($u->id));

        $this->actingAs($u)->get('/alertes')->assertOk();

        $this->assertSame(0, NotificationOutbox::unreadCountFor($u->id));
        $this->assertSame(0, NotificationOutbox::where('user_id', $u->id)->whereNull('read_at')->count());
    }

    public function test_home_bell_shows_unread_badge(): void
    {
        $u = User::factory()->create();
        $this->sentPush($u);
        $this->sentPush($u);

        $this->actingAs($u)->get('/dashboard')
            ->assertOk()
            ->assertSee('Alertes — 2 non lues');
    }
}
