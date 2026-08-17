<?php

namespace Tests\Feature;

use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// J8.1 — Fondation outbox (cadrage §7.14, PRD §4.15). Émetteur (fan-out + routage parent/enfant
// §4.15.5 + matrice §4.15.3 + pause §4.15.4) et drain (cron / à la demande + retry/backoff).
class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    /** Branche un FakeChannel partagé sur les deux canaux et le renvoie pour les assertions. */
    private function fakeChannel(): FakeChannel
    {
        $fake = new FakeChannel;
        $this->app->instance(FakeChannel::class, $fake);
        config([
            'club.notifications.channels.push' => FakeChannel::class,
            'club.notifications.channels.email' => FakeChannel::class,
        ]);

        return $fake;
    }

    // ── Émetteur : routage parent/enfant (§4.15.5) ──

    public function test_adult_without_guardian_is_sole_recipient(): void
    {
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        // push + email, destinataire = l'intéressé.
        $this->assertSame(2, NotificationOutbox::where('user_id', $user->id)->count());
        $this->assertSame(2, NotificationOutbox::count());
    }

    public function test_p1_routes_to_guardian_only(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->minorP1()->create(['guardian_id' => $guardian->id]);

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $child);

        $this->assertSame(0, NotificationOutbox::where('user_id', $child->id)->count());
        $this->assertSame(2, NotificationOutbox::where('user_id', $guardian->id)->count());
    }

    public function test_p2_routes_to_both_child_and_guardian(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'email' => 'child@club.test',
        ]);

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $child);

        $this->assertSame(2, NotificationOutbox::where('user_id', $child->id)->count());
        $this->assertSame(2, NotificationOutbox::where('user_id', $guardian->id)->count());
    }

    // ── Émetteur : matrice (§4.15.3) + pause (§4.15.4) + adresse ──

    public function test_default_all_channels_enabled_without_preferences(): void
    {
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionModified, $user);

        $this->assertSame(['email', 'push'], NotificationOutbox::where('user_id', $user->id)
            ->pluck('channel')->sort()->values()->all());
    }

    public function test_matrix_opt_out_blocks_one_channel(): void
    {
        $user = User::factory()->create();
        NotificationPreferences::create([
            'user_id' => $user->id,
            'matrix' => ['session_cancelled' => ['push' => false]],
            'paused' => false,
        ]);

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->assertSame(['email'], NotificationOutbox::where('user_id', $user->id)
            ->pluck('channel')->all());
    }

    public function test_global_pause_blocks_all_channels(): void
    {
        $user = User::factory()->create();
        NotificationPreferences::create([
            'user_id' => $user->id,
            'matrix' => [],
            'paused' => true,
        ]);

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_email_channel_skipped_when_recipient_has_no_address(): void
    {
        // Type email seul + destinataire sans adresse → aucune ligne.
        $guardian = User::factory()->create(['email' => null]);
        $orphan = User::factory()->minorP1()->create(['guardian_id' => $guardian->id]);

        $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $orphan);

        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_type_restricts_channels_even_if_more_requested(): void
    {
        $user = User::factory()->create();

        // AthleteReactivated = email seul ; un push explicite est ignoré.
        $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user, channels: ['push', 'email']);

        $this->assertSame(['email'], NotificationOutbox::where('user_id', $user->id)
            ->pluck('channel')->all());
    }

    // ── Drain : succès, échéance, retry/backoff ──

    public function test_drain_due_marks_line_sent(): void
    {
        $fake = $this->fakeChannel();
        $user = User::factory()->create();
        $lines = $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $stats = app(OutboxDrainer::class)->drainDue();

        $this->assertSame(2, $stats['sent']);
        $this->assertCount(2, $fake->sent);
        $this->assertSame(0, NotificationOutbox::where('status', 'pending')->count());
        $this->assertNotNull($lines->first()->fresh()->sent_at);
    }

    public function test_drain_due_skips_lines_not_yet_available(): void
    {
        $this->fakeChannel();
        $user = User::factory()->create();
        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);
        NotificationOutbox::query()->update(['available_at' => Carbon::now()->addHour()]);

        $stats = app(OutboxDrainer::class)->drainDue();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(2, NotificationOutbox::where('status', 'pending')->count());
    }

    public function test_drain_due_respects_batch_limit(): void
    {
        $this->fakeChannel();
        // 3 lignes dues (1 type email seul × 3 destinataires).
        User::factory()->count(3)->create()->each(
            fn (User $u) => $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $u)
        );

        $stats = app(OutboxDrainer::class)->drainDue(limit: 2);

        $this->assertSame(2, $stats['sent']);
        $this->assertSame(1, NotificationOutbox::where('status', 'pending')->count());
    }

    public function test_failed_send_reschedules_with_backoff(): void
    {
        $fake = $this->fakeChannel();
        $fake->shouldFail = true;
        $user = User::factory()->create();
        $line = $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user)->first();

        $stats = app(OutboxDrainer::class)->drainDue();

        $this->assertSame(1, $stats['retried']);
        $line->refresh();
        $this->assertSame('pending', $line->status);
        $this->assertSame(1, $line->attempts);
        // Backoff 1re tentative = +1 min.
        $this->assertEqualsWithDelta(
            Carbon::now()->addMinute()->timestamp,
            $line->available_at->timestamp,
            5,
        );
    }

    public function test_line_fails_permanently_after_max_attempts(): void
    {
        $fake = $this->fakeChannel();
        $fake->shouldFail = true;
        $user = User::factory()->create();
        $line = $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user)->first();
        // Déjà à la dernière tentative autorisée, échéance échue.
        $line->update(['attempts' => OutboxDrainer::MAX_ATTEMPTS, 'available_at' => Carbon::now()->subMinute()]);

        $stats = app(OutboxDrainer::class)->drainDue();

        $this->assertSame(1, $stats['failed']);
        $line->refresh();
        $this->assertSame('failed', $line->status);
        $this->assertSame(OutboxDrainer::MAX_ATTEMPTS + 1, $line->attempts);
    }

    // ── Drain à la demande (envoi prioritaire §7.14) ──

    public function test_drain_now_ignores_availability_window(): void
    {
        $fake = $this->fakeChannel();
        $user = User::factory()->create();
        $line = $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user)->first();
        $line->update(['available_at' => Carbon::now()->addHour()]); // pas encore échue

        $stats = app(OutboxDrainer::class)->drainNow([$line]);

        $this->assertSame(1, $stats['sent']);
        $this->assertSame('sent', $line->fresh()->status);
        $this->assertCount(1, $fake->sent);
    }

    public function test_drain_now_skips_already_sent_lines(): void
    {
        $this->fakeChannel();
        $user = User::factory()->create();
        $line = $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user)->first();
        $line->update(['status' => 'sent', 'sent_at' => Carbon::now()]);

        $stats = app(OutboxDrainer::class)->drainNow([$line]);

        $this->assertSame(['sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0], $stats);
    }

    // ── Commande cron ──

    public function test_drain_command_runs_and_reports(): void
    {
        $this->fakeChannel();
        $user = User::factory()->create();
        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->artisan('notifications:drain')
            ->assertExitCode(0)
            ->expectsOutputToContain('2 envoyée(s)');

        $this->assertSame(0, NotificationOutbox::where('status', 'pending')->count());
    }
}
