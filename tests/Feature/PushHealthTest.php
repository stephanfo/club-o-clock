<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Livewire\Admin\Outbox;
use App\Models\NotificationOutbox;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use App\Notifications\Push\PushDeliveryResult;
use App\Notifications\Push\WebPushSender;
use App\Services\OutboxAdminService;
use App\Services\PushHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Santé du push côté serveur (#97) : issue `no_target`, suivi par appareil, purge des appareils
// morts, indicateurs admin.
class PushHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @var object{expire:list<string>,fail:list<string>,sent:list<string>} */
    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();

        config(['club.notifications.channels.push' => PushChannel::class]);
        $this->sender = new class implements WebPushSender
        {
            public array $expire = [];

            public array $fail = [];

            public array $sent = [];

            public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult
            {
                if (in_array($subscription->endpoint, $this->expire, true)) {
                    return PushDeliveryResult::expired();
                }
                if (in_array($subscription->endpoint, $this->fail, true)) {
                    return PushDeliveryResult::failed();
                }
                $this->sent[] = $subscription->endpoint;

                return PushDeliveryResult::delivered();
            }
        };
        $this->app->instance(WebPushSender::class, $this->sender);
    }

    private function line(User $user, array $payload = ['session_id' => 1]): NotificationOutbox
    {
        return NotificationOutbox::create([
            'type' => NotificationType::SessionCancelled->value, 'channel' => 'push', 'payload' => $payload,
            'user_id' => $user->id, 'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
        ]);
    }

    private function sub(User $user, string $endpoint, array $health = []): PushSubscription
    {
        $sub = PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'k', 'auth' => 'a', 'content_encoding' => 'aes128gcm',
        ]);
        if ($health !== []) {
            $sub->forceFill($health)->save();
        }

        return $sub->refresh();
    }

    private function drain(): array
    {
        return app(OutboxDrainer::class)->drainDue();
    }

    // ── Issue `no_target` ──

    public function test_push_without_any_device_ends_no_target_not_sent(): void
    {
        $user = User::factory()->create();
        $line = $this->line($user, ['session_id' => 1, 'subject_id' => 99, 'subject_first_name' => 'Léa']);

        $stats = $this->drain();

        $line->refresh();
        $this->assertSame('no_target', $line->status, 'Rien n\'est parti : la ligne ne doit pas se dire envoyée.');
        $this->assertNull($line->sent_at);
        $this->assertSame(0, $line->attempts, 'Pas de retry : personne à qui l\'envoyer.');
        $this->assertSame('Léa', $line->payload['subject_first_name'], 'Payload intact : la ligne reste rejouable.');
        $this->assertSame(1, $stats['no_target']);
    }

    public function test_all_devices_dead_ends_no_target(): void
    {
        $user = User::factory()->create();
        $this->sub($user, 'https://push/mort-1');
        $this->sub($user, 'https://push/mort-2');
        $this->sender->expire = ['https://push/mort-1', 'https://push/mort-2'];
        $line = $this->line($user);

        $this->drain();

        $this->assertSame('no_target', $line->fresh()->status);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_one_delivered_device_is_enough_for_sent(): void
    {
        $user = User::factory()->create();
        $this->sub($user, 'https://push/mort');
        $this->sub($user, 'https://push/vivant');
        $this->sender->expire = ['https://push/mort'];
        $line = $this->line($user);

        $this->drain();

        $this->assertSame('sent', $line->fresh()->status);
    }

    public function test_no_target_push_stays_readable_in_the_alerts_bell(): void
    {
        $user = User::factory()->create();
        $sent = $this->line($user);
        $this->drain();
        $this->assertSame('no_target', $sent->fresh()->status);

        $this->assertSame([$sent->id], NotificationOutbox::alertsFor($user->id)->pluck('id')->all());
    }

    public function test_admin_can_replay_a_no_target_line(): void
    {
        $user = User::factory()->create();
        $line = $this->line($user);
        $this->drain();

        $n = app(OutboxAdminService::class)->retry([$line->id], User::factory()->admin()->create());

        $this->assertSame(1, $n);
        $this->assertSame('pending', $line->fresh()->status);
    }

    // ── Suivi par appareil ──

    public function test_delivery_records_success_and_resets_failures(): void
    {
        $user = User::factory()->create();
        $sub = $this->sub($user, 'https://push/a', ['failure_count' => 3, 'last_failure_at' => now()->subHour()]);
        $this->line($user);

        $this->drain();

        $sub->refresh();
        $this->assertNotNull($sub->last_success_at);
        $this->assertSame(0, $sub->failure_count, 'Les échecs comptés sont CONSÉCUTIFS.');
    }

    public function test_transient_failure_is_counted_and_retried(): void
    {
        $user = User::factory()->create();
        $sub = $this->sub($user, 'https://push/a');
        $this->sender->fail = ['https://push/a'];
        $line = $this->line($user);

        $this->drain();

        $sub->refresh();
        $this->assertSame(1, $sub->failure_count);
        $this->assertNotNull($sub->last_failure_at);
        $this->assertSame('pending', $line->fresh()->status);
        $this->assertSame(1, $line->fresh()->attempts);
    }

    public function test_device_without_success_for_30_days_is_purged_at_the_fifth_failure(): void
    {
        $user = User::factory()->create();
        $this->sub($user, 'https://push/abandonne', [
            'failure_count' => PushChannel::PURGE_AFTER_FAILURES - 1,
            'last_success_at' => now()->subDays(PushChannel::PURGE_AFTER_DAYS + 1),
        ]);
        $this->sender->fail = ['https://push/abandonne'];
        $line = $this->line($user);

        $this->drain();

        $this->assertSame(0, PushSubscription::count());
        $this->assertSame('no_target', $line->fresh()->status, 'Seul appareil purgé : plus personne à qui envoyer.');
    }

    public function test_device_with_a_recent_success_is_kept_despite_five_failures(): void
    {
        $user = User::factory()->create();
        $sub = $this->sub($user, 'https://push/a', [
            'failure_count' => PushChannel::PURGE_AFTER_FAILURES - 1,
            'last_success_at' => now()->subDays(10),
        ]);
        $this->sender->fail = ['https://push/a'];

        $this->line($user);
        $this->drain();

        $this->assertSame(PushChannel::PURGE_AFTER_FAILURES, $sub->fresh()->failure_count);
    }

    public function test_recent_device_never_reached_is_kept_despite_five_failures(): void
    {
        $user = User::factory()->create();
        // Abonné hier, jamais livré : la référence est la date d'abonnement, pas l'absence de succès.
        $sub = $this->sub($user, 'https://push/a', ['failure_count' => PushChannel::PURGE_AFTER_FAILURES - 1]);
        $this->sender->fail = ['https://push/a'];

        $this->line($user);
        $this->drain();

        $this->assertNotNull($sub->fresh());
    }

    public function test_old_device_never_reached_is_purged_at_the_fifth_failure(): void
    {
        $user = User::factory()->create();
        $sub = $this->sub($user, 'https://push/a', ['failure_count' => PushChannel::PURGE_AFTER_FAILURES - 1]);
        $sub->forceFill(['created_at' => now()->subDays(PushChannel::PURGE_AFTER_DAYS + 5)])->save();
        $this->sender->fail = ['https://push/a'];

        $this->line($user);
        $this->drain();

        $this->assertNull($sub->fresh());
    }

    // ── Santé admin ──

    public function test_health_summary_counts_devices_and_recent_outcomes(): void
    {
        $users = User::factory()->count(3)->create();
        $this->sub($users[0], 'https://push/ok');
        $this->sub($users[1], 'https://push/ko', ['failure_count' => 2]);
        foreach (['sent', 'sent', 'failed', 'no_target'] as $status) {
            $this->line($users[2])->update(['status' => $status]);
        }
        // Hors fenêtre : ne compte pas.
        $vieux = $this->line($users[2]);
        $vieux->update(['status' => 'failed']);
        NotificationOutbox::whereKey($vieux->id)->update(['updated_at' => now()->subDays(2)]);

        $h = app(PushHealthService::class)->summary();

        $this->assertSame(2, $h['devices']);
        $this->assertSame(1, $h['devicesFailing']);
        $this->assertSame([2, 1, 1], [$h['delivered'], $h['failing'], $h['noTarget']]);
        $this->assertFalse($h['alert'], 'Trois essais : trop peu pour parler de panne.');
    }

    public function test_outbox_screen_alerts_on_massive_push_failures(): void
    {
        $user = User::factory()->create();
        foreach (['failed', 'failed', 'failed', 'sent', 'sent'] as $status) {
            $this->line($user)->update(['status' => $status]);
        }

        Livewire::actingAs(User::factory()->admin()->create())->test(Outbox::class)
            ->assertSee('Échecs massifs du push')
            ->assertSee('3 essai(s) sur 5', false);
    }

    public function test_no_target_lines_do_not_raise_the_alert(): void
    {
        $user = User::factory()->create();
        foreach (array_fill(0, 6, 'no_target') as $status) {
            $this->line($user)->update(['status' => $status]);
        }
        $this->line($user)->update(['status' => 'sent']);

        Livewire::actingAs(User::factory()->admin()->create())->test(Outbox::class)
            ->assertSee('6 sans destinataire')
            ->assertDontSee('Échecs massifs du push');
    }

    public function test_outbox_screen_filters_no_target(): void
    {
        $sans = User::factory()->create(['first_name' => 'Sansappareil']);
        $avec = User::factory()->create(['first_name' => 'Avecappareil']);
        $this->line($sans)->update(['status' => 'no_target']);
        $this->line($avec)->update(['status' => 'sent']);

        Livewire::actingAs(User::factory()->admin()->create())->test(Outbox::class)
            ->set('status', 'no_target')
            ->assertSee('Sansappareil')
            ->assertDontSee('Avecappareil');
    }

    public function test_member_page_flags_a_member_without_any_push_device(): void
    {
        $admin = User::factory()->admin()->create();
        $sans = User::factory()->create();
        $avec = User::factory()->create();
        $this->sub($avec, 'https://push/a');

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $sans])
            ->assertSee('Aucun appareil abonné au push');
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $avec])
            ->assertSee('Push : 1 appareil')
            ->assertDontSee('Aucun appareil abonné au push');
    }

    public function test_member_page_says_nothing_for_a_minor_without_account(): void
    {
        $parent = User::factory()->create();
        $p1 = User::factory()->minorP1()->create(['guardian_id' => $parent->id]);

        Livewire::actingAs(User::factory()->admin()->create())->test(MemberShow::class, ['user' => $p1])
            ->assertDontSee('appareil abonné au push')
            ->assertDontSee('Push :');
    }
}
