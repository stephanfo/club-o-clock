<?php

namespace Tests\Feature;

use App\Mail\OutboxNotificationMail;
use App\Models\NotificationOutbox;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ChannelManager;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\LogChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Notifications\Push\PushDeliveryResult;
use App\Notifications\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Tests\TestCase;

// J8.6 — Livraison réelle. Rendu générique partagé (titre/corps/lien) + canaux push (VAPID, derrière
// une couture testable) et email (Mailable transactionnel). Le drain reste inchangé (J8.1).
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function line(NotificationType $type, ?User $user, array $payload = [], string $channel = 'push'): NotificationOutbox
    {
        return NotificationOutbox::create([
            'type' => $type->value,
            'channel' => $channel,
            'payload' => $payload,
            'user_id' => $user?->id,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    private function sub(User $user, string $endpoint): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'pub-key',
            'auth' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    // ── Rendu générique (NotificationRenderer) ──

    public function test_render_uses_type_label_and_description(): void
    {
        $line = $this->line(NotificationType::SessionCancelled, null, ['session_id' => 7]);
        $rendered = app(NotificationRenderer::class)->render($line);

        $this->assertSame(NotificationType::SessionCancelled->label(), $rendered['title']);
        $this->assertSame(NotificationType::SessionCancelled->description(), $rendered['body']);
    }

    public function test_render_deep_links_session_types_to_session_page(): void
    {
        $line = $this->line(NotificationType::WaitlistPromoted, null, ['session_id' => 42]);

        $this->assertStringEndsWith('/seances/42', app(NotificationRenderer::class)->render($line)['url']);
    }

    /** Chaque type DOIT se rendre sans exception : un case oublié dans urlFor() jette
     *  UnhandledMatchError au drain → la notification n'arrive jamais (bug EnrolledByCoach). */
    public function test_every_notification_type_renders_without_error(): void
    {
        $renderer = app(NotificationRenderer::class);

        foreach (NotificationType::cases() as $type) {
            $rendered = $renderer->render($this->line($type, null, ['session_id' => 1, 'token' => 't']));
            $this->assertNotSame('', $rendered['url'], "urlFor vide pour {$type->value}");
        }
    }

    public function test_render_links_template_recap_to_planning(): void
    {
        $line = $this->line(NotificationType::CoachTemplateRecap, null, ['template_id' => 3, 'count' => 5]);

        $this->assertStringEndsWith('/planning', app(NotificationRenderer::class)->render($line)['url']);
    }

    public function test_render_links_invitation_to_activation_with_token(): void
    {
        $line = $this->line(NotificationType::GuardianshipInvitation, null, ['token' => 'abc123']);

        $this->assertStringEndsWith('/invitation/abc123', app(NotificationRenderer::class)->render($line)['url']);
    }

    public function test_render_links_severed_to_profil_and_reactivated_to_dashboard(): void
    {
        $severed = $this->line(NotificationType::GuardianshipSevered, null, ['ward_id' => 1]);
        $reactivated = $this->line(NotificationType::AthleteReactivated, null, ['user_id' => 1]);

        $this->assertStringEndsWith('/profil', app(NotificationRenderer::class)->render($severed)['url']);
        $this->assertStringEndsWith('/dashboard', app(NotificationRenderer::class)->render($reactivated)['url']);
    }

    /** Lue par le GARANT (§4.15.5), la réactivation parle de l'enfant : le dashboard du parent ne
     *  lui montrait rien de ce qui avait changé. Cas complémentaire du test ci-dessus, qui garde le
     *  cas « lu par l'intéressé ». Détail complet dans NotificationContexteSujetTest. */
    public function test_render_links_reactivated_to_children_when_read_by_the_guardian(): void
    {
        $guardian = User::factory()->create();
        $line = $this->line(NotificationType::AthleteReactivated, $guardian, ['user_id' => $guardian->id + 1]);

        $this->assertStringEndsWith('/enfants', app(NotificationRenderer::class)->render($line)['url']);
    }

    // ── Canal push (PushChannel + couture WebPushSender) ──

    private function fakeSender(): object
    {
        $fake = new class implements WebPushSender
        {
            /** @var list<string> */
            public array $sent = [];

            /** @var list<string> payloads JSON envoyés, dans l'ordre (cf. test des icônes PWA). */
            public array $payloads = [];

            /** @var list<string> endpoints à marquer expirés (404/410) */
            public array $expire = [];

            /** @var list<string> endpoints en échec transitoire */
            public array $fail = [];

            public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult
            {
                if (in_array($subscription->endpoint, $this->expire, true)) {
                    return PushDeliveryResult::expired();
                }
                if (in_array($subscription->endpoint, $this->fail, true)) {
                    return PushDeliveryResult::failed();
                }
                $this->sent[] = $subscription->endpoint;
                $this->payloads[] = $payloadJson;

                return PushDeliveryResult::delivered();
            }
        };

        $this->app->instance(WebPushSender::class, $fake);

        return $fake;
    }

    public function test_push_delivers_to_each_device(): void
    {
        $fake = $this->fakeSender();
        $user = User::factory()->create();
        $this->sub($user, 'https://push/a');
        $this->sub($user, 'https://push/b');

        $this->assertTrue(app(PushChannel::class)->send($this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1])));
        $this->assertEqualsCanonicalizing(['https://push/a', 'https://push/b'], $fake->sent);
    }

    public function test_push_prunes_expired_subscription_and_is_terminal(): void
    {
        $fake = $this->fakeSender();
        $fake->expire = ['https://push/dead'];
        $user = User::factory()->create();
        $this->sub($user, 'https://push/dead');

        // Tous les endpoints sont morts → rien à retenter : terminal (true), abonnement purgé.
        $this->assertTrue(app(PushChannel::class)->send($this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1])));
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor('https://push/dead')]);
    }

    public function test_push_keeps_live_device_when_another_expires(): void
    {
        $fake = $this->fakeSender();
        $fake->expire = ['https://push/dead'];
        $user = User::factory()->create();
        $this->sub($user, 'https://push/dead');
        $this->sub($user, 'https://push/live');

        $this->assertTrue(app(PushChannel::class)->send($this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1])));
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor('https://push/dead')]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor('https://push/live')]);
    }

    public function test_push_transient_failure_triggers_retry(): void
    {
        $fake = $this->fakeSender();
        $fake->fail = ['https://push/flaky'];
        $user = User::factory()->create();
        $this->sub($user, 'https://push/flaky');

        // Endpoint vivant mais échec transitoire → false : le drain retentera, l'abonnement reste.
        $this->assertFalse(app(PushChannel::class)->send($this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1])));
        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor('https://push/flaky')]);
    }

    public function test_push_without_subscription_is_terminal(): void
    {
        $this->fakeSender();
        $user = User::factory()->create();

        // Aucun appareil abonné : rien à pousser, inutile de retenter → terminal.
        $this->assertTrue(app(PushChannel::class)->send($this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1])));
    }

    // ── Canal email (EmailChannel + Mailable) ──

    public function test_email_sends_transactional_mail_to_recipient(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'athlete@club.test']);

        $this->assertTrue(app(EmailChannel::class)->send(
            $this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1], 'email')
        ));

        Mail::assertSent(OutboxNotificationMail::class, fn (OutboxNotificationMail $mail) => $mail->hasTo('athlete@club.test')
            && $mail->hasSubject(NotificationType::SessionCancelled->label()));
    }

    public function test_email_without_address_is_terminal_and_sends_nothing(): void
    {
        Mail::fake();
        $user = User::factory()->minorP1()->create(); // pas d'email propre

        $this->assertTrue(app(EmailChannel::class)->send(
            $this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1], 'email')
        ));

        Mail::assertNothingSent();
    }

    // ── Câblage : config → drivers réels (la bascule prod = 1 var d'env, sans toucher au drain) ──

    public function test_channel_manager_resolves_real_drivers_when_configured(): void
    {
        config([
            'club.notifications.channels.push' => PushChannel::class,
            'club.notifications.channels.email' => EmailChannel::class,
        ]);

        $manager = app(ChannelManager::class);

        $this->assertInstanceOf(PushChannel::class, $manager->driver('push'));
        $this->assertInstanceOf(EmailChannel::class, $manager->driver('email'));
    }

    // Régression : `.env.example` (donc la CI, donc tout contributeur qui en part) définit
    // NOTIF_EMAIL_DRIVER SANS VALEUR. Une variable présente mais vide ne déclenche pas le 2e
    // argument d'`env()` : le canal se résolvait sur '', le drain levait à l'instanciation, et la
    // ligne repartait en backoff `pending` au lieu de partir. Symptôme : deux tests d'invitation
    // verts en local (où le driver est renseigné) et rouges en CI.
    //
    // Le repli vit dans config/club.php, que `config()` court-circuiterait : on relit donc le
    // fichier de config lui-même, avec la variable vide telle que la CI la pose.
    public function test_an_empty_driver_variable_falls_back_to_the_log_channel(): void
    {
        $_ENV['NOTIF_EMAIL_DRIVER'] = $_SERVER['NOTIF_EMAIL_DRIVER'] = '';
        $_ENV['NOTIF_PUSH_DRIVER'] = $_SERVER['NOTIF_PUSH_DRIVER'] = '';

        try {
            $club = require config_path('club.php');
        } finally {
            unset($_ENV['NOTIF_EMAIL_DRIVER'], $_SERVER['NOTIF_EMAIL_DRIVER']);
            unset($_ENV['NOTIF_PUSH_DRIVER'], $_SERVER['NOTIF_PUSH_DRIVER']);
        }

        $this->assertSame(LogChannel::class, $club['notifications']['channels']['email']);
        $this->assertSame(LogChannel::class, $club['notifications']['channels']['push']);
    }

    // Seconde ligne de défense : quelle que soit la SOURCE d'une valeur vide, le manager doit dire
    // « pas de driver configuré » et non laisser `make('')` lever un « Target class [] » que le
    // drain confondrait avec une panne de transport (et donc réessaierait indéfiniment).
    public function test_an_empty_driver_is_reported_as_missing_configuration(): void
    {
        config(['club.notifications.channels.email' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Aucun driver configuré pour le canal 'email'.");

        app(ChannelManager::class)->driver('email');
    }

    public function test_vapid_keys_command_outputs_a_keypair(): void
    {
        $this->artisan('club:vapid-keys')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertExitCode(0);
    }

    // Le transport HTTP Brevo dépend de symfony/http-client : sans lui, MAIL_MAILER=brevo planterait
    // en prod (le défaut « log » des tests le masquerait). Ce test garde la dépendance.
    public function test_brevo_mailer_transport_resolves(): void
    {
        config(['services.brevo.key' => 'dummy']);

        $transport = Mail::mailer('brevo')->getSymfonyTransport();

        $this->assertInstanceOf(BrevoApiTransport::class, $transport);
    }

    // Mail::fake() court-circuite le rendu : on vérifie séparément que la vue markdown rend bien.
    public function test_email_mailable_renders_title_and_deep_link(): void
    {
        $mail = new OutboxNotificationMail('Annulation de séance', 'Une séance est annulée', 'https://club.test/seances/9');

        $mail->assertSeeInHtml('Annulation de séance');
        $mail->assertSeeInHtml('https://club.test/seances/9');
    }

    public function test_ordinary_mail_footer_points_to_the_profile_settings(): void
    {
        // Contrôle positif apparié du test suivant : le pied de page « notifications » reste servi
        // à ce qui EST une notification réglable dans la matrice (§4.15.3).
        $mail = new OutboxNotificationMail('Annulation de séance', 'Corps', 'https://club.test/s/9');

        $mail->assertSeeInHtml('tout mettre en pause depuis ton profil');
    }

    public function test_transactional_mail_footer_does_not_promise_a_setting_that_does_not_exist(): void
    {
        // Une invitation traverse l'interrupteur club et la pause, et ne figure pas dans la matrice
        // du profil : lui servir « tu peux tout mettre en pause depuis ton profil » serait faux sur
        // les deux points, et enverrait l'adhérent chercher un réglage inexistant.
        $mail = new OutboxNotificationMail('Invitation', 'Corps', 'https://club.test/invitation/x', transactional: true);

        // Fragments sans apostrophe : assertSeeInHtml échappe la chaîne attendue (&#039;) alors que
        // le corps rendu porte une apostrophe littérale — l'assertion échouerait sur un texte
        // pourtant présent. On vise donc les deux moitiés porteuses de sens.
        $mail->assertSeeInHtml('accès à ton compte');
        $mail->assertSeeInHtml('pas concerné par tes réglages de notifications ni par la mise en pause');
        $mail->assertDontSeeInHtml('tout mettre en pause depuis ton profil');
    }

    public function test_invitation_line_selects_the_transactional_footer(): void
    {
        // Le drapeau est dérivé du TYPE de la ligne, pas passé à la main : c'est le câblage réel
        // (EmailChannel) qu'on vérifie ici, pas seulement la vue.
        Mail::fake();
        $user = User::factory()->create(['email' => 'invite@club.test']);

        app(EmailChannel::class)->send(
            $this->line(NotificationType::MemberInvitation, $user, ['token' => 'abc'], 'email')
        );

        Mail::assertSent(OutboxNotificationMail::class, fn (OutboxNotificationMail $mail) => $mail->transactional === true);
    }

    public function test_ordinary_line_selects_the_ordinary_footer(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'athlete2@club.test']);

        app(EmailChannel::class)->send(
            $this->line(NotificationType::SessionCancelled, $user, ['session_id' => 1], 'email')
        );

        Mail::assertSent(OutboxNotificationMail::class, fn (OutboxNotificationMail $mail) => $mail->transactional === false);
    }
}
