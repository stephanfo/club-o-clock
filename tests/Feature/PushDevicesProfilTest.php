<?php

namespace Tests\Feature;

use App\Livewire\Profil;
use App\Models\ClubSettings;
use App\Models\NotificationPreferences;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Notifications\Push\PushDeliveryResult;
use App\Notifications\Push\WebPushSender;
use App\Support\DeviceLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

// Onglet Notifs, côté adhérent (#97) : notification de test, « Mes appareils », avertissement.
class PushDevicesProfilTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';

    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new class implements WebPushSender
        {
            public array $sent = [];

            public string $mode = 'delivered';

            public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult
            {
                $this->sent[] = [$subscription->endpoint, json_decode($payloadJson, true)];

                return match ($this->mode) {
                    'expired' => PushDeliveryResult::expired(),
                    'failed' => PushDeliveryResult::failed(),
                    default => PushDeliveryResult::delivered(),
                };
            }
        };
        $this->app->instance(WebPushSender::class, $this->sender);
    }

    private function sub(User $user, string $endpoint, ?string $ua = null): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'k', 'auth' => 'a', 'content_encoding' => 'aes128gcm', 'user_agent' => $ua,
        ]);
    }

    private function notifs(User $user)
    {
        return Livewire::actingAs($user)->test(Profil::class)->set('tab', 'notifs');
    }

    // ── Notification de test ──

    public function test_test_push_goes_to_this_device_only(): void
    {
        $u = User::factory()->create();
        $ici = $this->sub($u, 'https://push/ici');
        $this->sub($u, 'https://push/autre');

        $this->notifs($u)->call('sendTestPush', 'https://push/ici')
            ->assertSee('Notification envoyée');

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('https://push/ici', $this->sender->sent[0][0]);
        $this->assertSame('Notification de test', $this->sender->sent[0][1]['title']);
        $this->assertNotNull($ici->fresh()->last_success_at, 'Le test tient la santé de l\'appareil comme un vrai envoi.');
    }

    public function test_test_push_refuses_another_members_device(): void
    {
        $moi = User::factory()->create();
        $autre = User::factory()->create();
        $this->sub($autre, 'https://push/a-lui');

        $this->notifs($moi)->call('sendTestPush', 'https://push/a-lui')
            ->assertSee('pas abonné');

        $this->assertSame([], $this->sender->sent);
    }

    public function test_test_push_is_rate_limited(): void
    {
        $u = User::factory()->create();
        $this->sub($u, 'https://push/ici');
        RateLimiter::clear('push-test:'.$u->id);

        $c = $this->notifs($u);
        for ($i = 0; $i < Profil::TEST_PUSH_MAX; $i++) {
            $c->call('sendTestPush', 'https://push/ici');
        }
        $c->call('sendTestPush', 'https://push/ici')->assertSee('Trop d');

        $this->assertCount(Profil::TEST_PUSH_MAX, $this->sender->sent);
    }

    public function test_test_push_to_a_dead_device_purges_it_and_says_so(): void
    {
        $u = User::factory()->create();
        $this->sub($u, 'https://push/mort');
        $this->sender->mode = 'expired';

        $this->notifs($u)->call('sendTestPush', 'https://push/mort')
            ->assertSee('ne reconnaît plus cet appareil');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_test_push_transient_failure_is_counted(): void
    {
        $u = User::factory()->create();
        $sub = $this->sub($u, 'https://push/ici');
        $this->sender->mode = 'failed';

        $this->notifs($u)->call('sendTestPush', 'https://push/ici')
            ->assertSee('injoignable');

        $this->assertSame(1, $sub->fresh()->failure_count);
    }

    // ── Mes appareils ──

    public function test_devices_list_shows_my_devices_only(): void
    {
        $u = User::factory()->create();
        $this->sub($u, 'https://push/iphone', self::IPHONE)->forceFill(['last_success_at' => now()->subHours(2)])->save();
        $this->sub(User::factory()->create(), 'https://push/android', self::ANDROID);

        $this->notifs($u)
            ->assertSee('Mes appareils')
            ->assertSee('iPhone · Safari')
            ->assertSee('Dernier envoi réussi')
            ->assertDontSee('Android · Chrome');
    }

    public function test_member_removes_one_of_his_devices(): void
    {
        $u = User::factory()->create();
        $vieux = $this->sub($u, 'https://push/vieux');
        $this->sub($u, 'https://push/ici');

        $this->notifs($u)->call('removePushDevice', $vieux->id)->assertSee('Appareil retiré');

        $this->assertNull($vieux->fresh());
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_member_cannot_remove_another_members_device(): void
    {
        $sien = $this->sub(User::factory()->create(), 'https://push/a-lui');

        $this->notifs(User::factory()->create())->call('removePushDevice', $sien->id)
            ->assertSee('déjà plus abonné');

        $this->assertNotNull($sien->fresh());
    }

    // ── Avertissement « aucun appareil » ──

    public function test_warning_when_push_is_wanted_but_no_device_is_subscribed(): void
    {
        $this->notifs(User::factory()->create())
            ->assertSee('Aucun appareil ne reçoit tes notifications push')
            ->assertDontSee('Mes appareils');
    }

    public function test_no_warning_with_a_subscribed_device(): void
    {
        $u = User::factory()->create();
        $this->sub($u, 'https://push/ici');

        $this->notifs($u)->assertDontSee('Aucun appareil ne reçoit tes notifications push');
    }

    public function test_no_warning_when_paused_or_push_unwanted(): void
    {
        $pause = User::factory()->create();
        NotificationPreferences::create(['user_id' => $pause->id, 'paused' => true, 'matrix' => []]);
        $this->notifs($pause)->assertDontSee('Aucun appareil ne reçoit tes notifications push');

        $emailSeul = User::factory()->create();
        NotificationPreferences::create(['user_id' => $emailSeul->id, 'paused' => false, 'matrix' => collect(NotificationType::cases())
            ->mapWithKeys(fn ($t) => [$t->value => ['push' => false, 'email' => true]])->all()]);
        $this->notifs($emailSeul)->assertDontSee('Aucun appareil ne reçoit tes notifications push');
    }

    public function test_no_push_block_at_all_when_the_club_closed_push(): void
    {
        ClubSettings::current()->update(['notif_push_enabled' => false]);

        $this->notifs(User::factory()->create())
            ->assertDontSee('Aucun appareil ne reçoit tes notifications push')
            ->assertDontSee('notification de test');
    }

    public function test_device_label_recognises_common_browsers(): void
    {
        $this->assertSame('iPhone · Safari', DeviceLabel::from(self::IPHONE));
        $this->assertSame('Android · Chrome', DeviceLabel::from(self::ANDROID));
        $this->assertSame('Android · Samsung Internet', DeviceLabel::from('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36'));
        $this->assertSame('Windows · Edge', DeviceLabel::from('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0'));
        $this->assertSame('Appareil inconnu', DeviceLabel::from(null));
    }
}
