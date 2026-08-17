<?php

namespace Tests\Feature;

use App\Livewire\Admin\Outbox;
use App\Models\AuditLog;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use App\Notifications\OutboxDrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// J8.3 — Écran bureau de gestion de l'outbox (§4.15.6) : consultation filtrée + rattrapage
// (annulation pending), envoi manuel immédiat, rejeu des failed. Admin uniquement, actions tracées.
class OutboxAdminTest extends TestCase
{
    use RefreshDatabase;

    private function line(array $attrs = []): NotificationOutbox
    {
        return NotificationOutbox::create(array_merge([
            'type' => 'session_cancelled', 'channel' => 'push',
            'payload' => ['session_id' => 1], 'user_id' => null,
            'status' => 'pending', 'attempts' => 0, 'available_at' => Carbon::now(),
        ], $attrs));
    }

    private function fakeChannels(): FakeChannel
    {
        $fake = new FakeChannel;
        $this->app->instance(FakeChannel::class, $fake);
        config([
            'club.notifications.channels.push' => FakeChannel::class,
            'club.notifications.channels.email' => FakeChannel::class,
        ]);

        return $fake;
    }

    // ── Accès ──

    public function test_non_admin_is_forbidden(): void
    {
        $coach = User::factory()->coach()->create();
        Livewire::actingAs($coach)->test(Outbox::class)->assertForbidden();
    }

    public function test_admin_can_view(): void
    {
        $admin = User::factory()->admin()->create();
        $this->line();
        Livewire::actingAs($admin)->test(Outbox::class)->assertOk();
    }

    // ── Filtres ──

    public function test_status_filter_narrows_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $this->line(['status' => 'pending']);
        $this->line(['status' => 'sent', 'sent_at' => Carbon::now()]);
        $this->line(['status' => 'failed', 'attempts' => 6]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->assertViewHas('total', 3)
            ->set('status', 'failed')
            ->assertViewHas('total', 1);
    }

    public function test_channel_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $this->line(['channel' => 'push']);
        $this->line(['channel' => 'email']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('channel', 'email')
            ->assertViewHas('total', 1);
    }

    // ── Annulation (rattrapage) ──

    public function test_cancel_selected_moves_pending_to_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        $l = $this->line(['status' => 'pending']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$l->id])
            ->call('cancelSelected');

        $this->assertSame('cancelled', $l->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'outbox_cancelled', 'actor_id' => $admin->id]);
    }

    public function test_cancel_ignores_non_pending(): void
    {
        $admin = User::factory()->admin()->create();
        $sent = $this->line(['status' => 'sent', 'sent_at' => Carbon::now()]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$sent->id])
            ->call('cancelSelected');

        $this->assertSame('sent', $sent->fresh()->status); // intouché
        $this->assertDatabaseMissing('audit_logs', ['action' => 'outbox_cancelled']);
    }

    public function test_cancelled_line_is_skipped_by_drain(): void
    {
        $this->fakeChannels();
        $cancelled = $this->line(['status' => 'cancelled']);

        app(OutboxDrainer::class)->drainDue();

        $this->assertSame('cancelled', $cancelled->fresh()->status); // pas envoyé
    }

    // ── Rejeu des échecs ──

    public function test_retry_resets_failed_to_pending_with_zero_attempts(): void
    {
        $admin = User::factory()->admin()->create();
        $failed = $this->line(['status' => 'failed', 'attempts' => 6, 'available_at' => Carbon::now()->subDay()]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$failed->id])
            ->call('retrySelected');

        $fresh = $failed->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertDatabaseHas('audit_logs', ['action' => 'outbox_retried']);
    }

    public function test_retry_ignores_non_failed_in_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = $this->line(['status' => 'pending']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$pending->id])
            ->call('retrySelected');

        $this->assertSame('pending', $pending->fresh()->status); // pas touché (retry ne vise que failed)
        $this->assertDatabaseMissing('audit_logs', ['action' => 'outbox_retried']);
    }

    // ── Envoi manuel immédiat ──

    public function test_push_selected_drains_pending_now(): void
    {
        $fake = $this->fakeChannels();
        $admin = User::factory()->admin()->create();
        $l = $this->line(['status' => 'pending']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$l->id])
            ->call('pushSelected');

        $this->assertSame('sent', $l->fresh()->status);
        $this->assertCount(1, $fake->sent);
        $this->assertDatabaseHas('audit_logs', ['action' => 'outbox_pushed']);
    }

    public function test_push_only_sends_pending_in_mixed_selection(): void
    {
        $fake = $this->fakeChannels();
        $admin = User::factory()->admin()->create();
        $pending = $this->line(['status' => 'pending']);
        $failed = $this->line(['status' => 'failed', 'attempts' => 6]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('selected', [$pending->id, $failed->id])
            ->call('pushSelected');

        $this->assertSame('sent', $pending->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status); // un failed n'est pas poussé
        $this->assertCount(1, $fake->sent);
    }

    public function test_push_all_pending_respects_filters(): void
    {
        $fake = $this->fakeChannels();
        $admin = User::factory()->admin()->create();
        $this->line(['status' => 'pending', 'channel' => 'push']);
        $this->line(['status' => 'pending', 'channel' => 'email']);
        $this->line(['status' => 'failed', 'channel' => 'push', 'attempts' => 6]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('channel', 'push')
            ->call('pushAllPending');

        // Seul le pending+push est poussé (le failed et le pending+email sont hors filtre/statut).
        $this->assertSame(1, NotificationOutbox::where('status', 'sent')->count());
        $this->assertCount(1, $fake->sent);
    }

    // ── Drawer ──

    public function test_detail_links_recipient_to_member_sheet(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $l = $this->line(['user_id' => $recipient->id]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->call('showDetail', $l->id)
            ->assertSeeHtml(route('admin.members.show', $recipient->id));
    }

    public function test_detail_does_not_link_anonymized_recipient(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $recipient->forceFill(['anonymized_at' => now()])->save();
        $l = $this->line(['user_id' => $recipient->id]);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->call('showDetail', $l->id)
            ->assertDontSeeHtml(route('admin.members.show', $recipient->id));
    }

    public function test_detail_action_cancels_open_line(): void
    {
        $admin = User::factory()->admin()->create();
        $l = $this->line(['status' => 'pending']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->call('showDetail', $l->id)
            ->assertSet('detailId', $l->id)
            ->call('cancelDetail')
            ->assertSet('detailId', null);

        $this->assertSame('cancelled', $l->fresh()->status);
    }

    // ── Consultation = lecture pure ──

    public function test_viewing_does_not_mutate_or_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->line(['status' => 'pending']);

        Livewire::actingAs($admin)->test(Outbox::class)
            ->set('status', 'pending')
            ->call('showDetail', NotificationOutbox::first()->id)
            ->call('closeDetail');

        $this->assertSame(0, NotificationOutbox::whereIn('status', ['sent', 'cancelled'])->count());
        $this->assertSame(0, AuditLog::count());
    }
}
