<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClubSettingsForm;
use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\NotificationType;
use App\Notifications\Push\PushDeliveryResult;
use App\Notifications\Push\WebPushSender;
use App\Services\ClubBrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Icônes PWA propres à l'instance (PRD §4.17, cadrage §7.16).
//
// L'enjeu couvert ici est le REPLI : le jeu livré dans public/icons/ reste versionné, de sorte
// qu'une instance qui n'a rien téléversé est installable en PWA. Un régression sur ce point ne
// produirait aucune erreur — seulement une PWA qui ne s'installe pas — d'où ces tests.
class ClubPwaIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_icon_url_falls_back_to_shipped_asset_when_nothing_uploaded(): void
    {
        $settings = ClubSettings::current();

        $this->assertStringContainsString('icons/icon-192.png', $settings->pwaIconUrl('icon_192'));
        $this->assertStringContainsString('icons/icon-512.png', $settings->pwaIconUrl('icon_512'));
        $this->assertStringContainsString('icons/apple-touch-icon.png', $settings->pwaIconUrl('icon_apple'));
    }

    public function test_uploaded_icon_replaces_the_shipped_one(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();

        app(ClubBrandingService::class)->replacePwaIcon(
            $settings, 'icon_192', UploadedFile::fake()->image('c.png', 192, 192), null
        );
        $settings->refresh();

        Storage::disk('public')->assertExists($settings->icon_192_path);
        $this->assertStringNotContainsString('icons/icon-192.png', $settings->pwaIconUrl('icon_192'));
        // Contrôle positif apparié : les autres variantes n'ont PAS bougé.
        $this->assertStringContainsString('icons/icon-512.png', $settings->pwaIconUrl('icon_512'));
    }

    public function test_wrong_dimensions_are_refused(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();

        $this->expectException(RuntimeException::class);

        app(ClubBrandingService::class)->replacePwaIcon(
            $settings, 'icon_192', UploadedFile::fake()->image('c.png', 64, 64), null
        );
    }

    public function test_non_image_is_refused_by_gd(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();

        $this->expectException(RuntimeException::class);

        app(ClubBrandingService::class)->replacePwaIcon(
            $settings, 'icon_192', UploadedFile::fake()->create('payload.png', 8), null
        );
    }

    public function test_replacing_an_icon_deletes_the_previous_directory(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $service = app(ClubBrandingService::class);

        $service->replacePwaIcon($settings, 'icon_192', UploadedFile::fake()->image('a.png', 192, 192), null);
        $settings->refresh();
        $first = $settings->icon_192_path;

        $service->replacePwaIcon($settings, 'icon_192', UploadedFile::fake()->image('b.png', 192, 192), null);
        $settings->refresh();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($settings->icon_192_path);
    }

    public function test_reset_restores_the_shipped_icons(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $service = app(ClubBrandingService::class);

        $service->replacePwaIcon($settings, 'icon_192', UploadedFile::fake()->image('a.png', 192, 192), null);
        $settings->refresh();
        $uploaded = $settings->icon_192_path;

        $service->resetPwaIcons($settings, null);
        $settings->refresh();

        $this->assertNull($settings->icon_192_path);
        Storage::disk('public')->assertMissing($uploaded);
        $this->assertStringContainsString('icons/icon-192.png', $settings->pwaIconUrl('icon_192'));
    }

    public function test_apple_icon_is_flattened_to_an_opaque_png(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();

        app(ClubBrandingService::class)->replacePwaIcon(
            $settings, 'icon_apple', UploadedFile::fake()->image('a.png', 180, 180), null
        );
        $settings->refresh();

        // iOS rend toute transparence résiduelle en NOIR : l'aplatissement n'est pas cosmétique.
        $image = imagecreatefromstring(Storage::disk('public')->get($settings->icon_apple_path));
        $this->assertNotFalse($image);
        $alpha = (imagecolorat($image, 0, 0) >> 24) & 0x7F;
        $this->assertSame(0, $alpha, "L'icône iOS doit être entièrement opaque.");
    }

    public function test_manifest_serves_the_uploaded_icon(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();

        app(ClubBrandingService::class)->replacePwaIcon(
            $settings, 'icon_192', UploadedFile::fake()->image('c.png', 192, 192), null
        );

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $sources = array_column($manifest['icons'], 'src');

        $this->assertNotContains('/icons/icon-192.png', $sources);
    }

    /**
     * Le payload push porte l'URL de l'icône, résolue côté serveur : public/sw.js est un fichier
     * STATIQUE, il ne peut pas lire ClubSettings (cadrage §7.16). Sans cette clé, la notification
     * s'affiche avec l'icône livrée alors que le club a téléversé la sienne — sans aucune erreur.
     */
    public function test_push_payload_carries_the_resolved_icon_url(): void
    {
        Storage::fake('public');
        $sender = $this->fakePushSender();
        $user = User::factory()->create();
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push/a',
            'endpoint_hash' => PushSubscription::hashFor('https://push/a'),
            'p256dh' => 'pub-key',
            'auth' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        // 1. Sans téléversement : l'icône livrée.
        app(PushChannel::class)->send($this->outboxLine($user));
        $shipped = json_decode($sender->payloads[0], true);
        $this->assertStringContainsString('icons/icon-192.png', $shipped['icon']);

        // 2. Après téléversement : celle du club. Contrôle positif apparié — sans lui, un `icon`
        // vide passerait les deux assertions.
        app(ClubBrandingService::class)->replacePwaIcon(
            ClubSettings::current(), 'icon_192', UploadedFile::fake()->image('c.png', 192, 192), null
        );

        app(PushChannel::class)->send($this->outboxLine($user));
        $custom = json_decode($sender->payloads[1], true);
        $this->assertStringNotContainsString('icons/icon-192.png', $custom['icon']);
        $this->assertStringContainsString('/storage/icons/', $custom['icon']);
    }

    /** Couture WebPushSender qui mémorise les payloads JSON (cf. NotificationDeliveryTest). */
    private function fakePushSender(): object
    {
        $fake = new class implements WebPushSender
        {
            /** @var list<string> */
            public array $payloads = [];

            public function send(PushSubscription $subscription, string $payloadJson): PushDeliveryResult
            {
                $this->payloads[] = $payloadJson;

                return PushDeliveryResult::delivered();
            }
        };

        $this->app->instance(WebPushSender::class, $fake);

        return $fake;
    }

    private function outboxLine(User $user): NotificationOutbox
    {
        return NotificationOutbox::create([
            'user_id' => $user->id,
            'type' => NotificationType::SessionCancelled->value,
            'channel' => 'push',
            'payload' => ['session_id' => 1],
            'status' => 'pending',
        ]);
    }

    public function test_non_admin_cannot_upload_or_reset_icons(): void
    {
        $athlete = User::factory()->create();

        Livewire::actingAs($athlete)
            ->test(ClubSettingsForm::class)
            ->assertForbidden();
    }
}
