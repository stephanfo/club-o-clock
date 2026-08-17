<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClubSettingsForm;
use App\Livewire\Profil;
use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Interrupteurs de canal à l'échelle du club (§4.17, extension de §4.15). Deux points d'application :
// filtre à l'émission (aucune ligne créée) et garde au drain (lignes déjà en file → cancelled).
class ClubNotificationChannelsTest extends TestCase
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

    /** Écrit un réglage du singleton. flushCache() car TestCase ne le vide qu'au setUp(). */
    private function setSettings(array $attributes): void
    {
        ClubSettings::current()->update($attributes);
        ClubSettings::flushCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
    }

    // ── Filtre à l'émission ──

    public function test_both_channels_open_by_default(): void
    {
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        // Non-régression : sans réglage explicite, le comportement d'origine est conservé.
        $this->assertSame(2, NotificationOutbox::count());
    }

    public function test_disabled_push_channel_creates_email_line_only(): void
    {
        $this->setSettings(['notif_push_enabled' => false]);
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->assertSame(1, NotificationOutbox::count());
        $this->assertSame('email', NotificationOutbox::first()->channel);
    }

    public function test_disabled_email_channel_creates_push_line_only(): void
    {
        $this->setSettings(['notif_email_enabled' => false]);
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->assertSame(1, NotificationOutbox::count());
        $this->assertSame('push', NotificationOutbox::first()->channel);
    }

    public function test_both_channels_disabled_creates_nothing(): void
    {
        $this->setSettings(['notif_push_enabled' => false, 'notif_email_enabled' => false]);
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_email_only_type_is_fully_suppressed_when_email_is_disabled(): void
    {
        // AthleteReactivated ne connaît que le canal email : le couper le supprime entièrement.
        $this->setSettings(['notif_email_enabled' => false]);
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::AthleteReactivated, $user);

        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_targeted_dispatch_also_respects_the_switch(): void
    {
        // dispatchTo() contourne volontairement le routage et reachable(), mais pas l'interrupteur.
        $this->setSettings(['notif_push_enabled' => false]);
        $user = User::factory()->create();

        $this->dispatcher()->dispatchTo(NotificationType::GuardianshipSevered, $user);

        $this->assertSame(0, NotificationOutbox::where('channel', 'push')->count());
        $this->assertSame(1, NotificationOutbox::where('channel', 'email')->count());
    }

    // ── Garde au drain ──

    public function test_drain_cancels_queued_line_whose_channel_was_closed_since(): void
    {
        $fake = $this->fakeChannel();
        $user = User::factory()->create();

        // Ligne émise canal ouvert, PUIS coupure du canal : c'est le cas de rattrapage.
        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);
        $this->setSettings(['notif_push_enabled' => false]);

        $stats = app(OutboxDrainer::class)->drainDue();

        $this->assertSame(1, $stats['cancelled']);
        $this->assertSame(1, $stats['sent']); // l'email est parti normalement

        $push = NotificationOutbox::where('channel', 'push')->firstOrFail();
        $this->assertSame('cancelled', $push->status);
        // Pas un échec de transport : ni tentative consommée, ni backoff.
        $this->assertSame(0, $push->attempts);
        $this->assertNull($push->sent_at);

        // Le canal coupé n'a jamais été sollicité : seule la ligne email est passée par le driver.
        $email = NotificationOutbox::where('channel', 'email')->firstOrFail();
        $this->assertSame([$email->id], $fake->sent);
    }

    public function test_cancelled_line_is_not_retried_on_a_later_drain(): void
    {
        $this->fakeChannel();
        $user = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user, channels: ['push']);
        $this->setSettings(['notif_push_enabled' => false]);

        app(OutboxDrainer::class)->drainDue();
        $second = app(OutboxDrainer::class)->drainDue();

        // Statut terminal : la passe suivante ne la voit plus (elle ne cible que `pending`).
        $this->assertSame(0, $second['cancelled']);
        $this->assertSame(1, NotificationOutbox::where('status', 'cancelled')->count());
    }

    // ── Réactivation ──

    public function test_reactivating_a_channel_restores_dispatch_and_keeps_personal_opt_out(): void
    {
        $user = User::factory()->create();
        // Opt-out personnel posé pendant la coupure : il doit survivre à la réactivation.
        NotificationPreferences::create([
            'user_id' => $user->id,
            'matrix' => [NotificationType::SessionCancelled->value => ['email' => false]],
            'paused' => false,
        ]);

        $this->setSettings(['notif_push_enabled' => false]);
        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);
        $this->assertSame(0, NotificationOutbox::count()); // push coupé, email opt-out

        $this->setSettings(['notif_push_enabled' => true]);
        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $user);

        // Le push repart ; l'email reste refusé par la préférence individuelle.
        $this->assertSame(1, NotificationOutbox::count());
        $this->assertSame('push', NotificationOutbox::first()->channel);
    }

    // ── Écran admin ──

    public function test_admin_toggle_persists_channel_and_records_audit(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleChannel', 'push')
            ->assertSet('notif_push_enabled', false);

        ClubSettings::flushCache();
        $this->assertFalse(ClubSettings::current()->notif_push_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'club_settings_updated']);
    }

    public function test_admin_toggle_ignores_an_unknown_channel(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleChannel', 'sms')
            ->assertSet('notif_push_enabled', true)
            ->assertSet('notif_email_enabled', true);

        ClubSettings::flushCache();
        $this->assertTrue(ClubSettings::current()->notif_push_enabled);
    }

    public function test_non_admin_cannot_reach_the_settings_screen(): void
    {
        $athlete = User::factory()->create(['roles' => ['athlete']]);

        $this->actingAs($athlete)->get(route('admin.settings'))->assertForbidden();
    }

    // ── Écran profil ──

    public function test_profile_exposes_closed_channel_and_explains_it(): void
    {
        $this->setSettings(['notif_push_enabled' => false]);
        $user = User::factory()->create(['roles' => ['athlete'], 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(Profil::class, ['tab' => 'notifs'])
            ->assertSet('clubChannels.push', false)
            ->assertSet('clubChannels.email', true)
            ->assertSee('désactivées par le club');
    }
}
