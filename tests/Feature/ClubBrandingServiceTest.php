<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Services\ClubBrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

// Upload du logo club (plan open source OS2) : ClubBrandingService génère des vignettes carrées
// à côté de l'original, pour un affichage net en petit format (topbar/profil, cf. logo.blade.php)
// — un fichier uploadé arbitraire (photo, export web) n'est pas conçu pour un downscale
// navigateur propre à 24-30px, contrairement à l'ancien pictogramme fixe.
class ClubBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replace_logo_generates_square_thumbnails(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $file = UploadedFile::fake()->image('logo.png', 400, 300); // non carré, exprès

        app(ClubBrandingService::class)->replaceLogo($settings, $file, null);
        $settings->refresh();

        $dir = dirname($settings->logo_path);
        Storage::disk('public')->assertExists("{$dir}/original.png");
        Storage::disk('public')->assertExists("{$dir}/thumb-64.png");
        Storage::disk('public')->assertExists("{$dir}/thumb-128.png");
    }

    public function test_logo_thumb_url_falls_back_to_default_when_no_logo(): void
    {
        $settings = ClubSettings::current();

        $this->assertStringContainsString('logo-default.png', $settings->logoThumbUrl(64));
    }

    public function test_replace_logo_deletes_previous_logo_directory(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $service = app(ClubBrandingService::class);

        $service->replaceLogo($settings, UploadedFile::fake()->image('first.png', 400, 400), null);
        $settings->refresh();
        $firstDir = dirname($settings->logo_path);

        $service->replaceLogo($settings, UploadedFile::fake()->image('second.png', 400, 400), null);
        $settings->refresh();

        Storage::disk('public')->assertMissing("{$firstDir}/original.png");
        Storage::disk('public')->assertMissing("{$firstDir}/thumb-64.png");
    }

    /**
     * Un fichier que GD ne sait pas décoder n'est PAS publié.
     *
     * Le service acceptait auparavant ce cas en silence (pas de vignette, original conservé). Or
     * le disque `public` est servi same-origin : tout ce qui y atterrit est atteignable par URL.
     * Un fichier non décodable est soit corrompu, soit un document exécutable déguisé (SVG, HTML
     * renommé .png) — dans les deux cas il n'a rien à faire sous une URL de l'application.
     */
    public function test_undecodable_file_is_rejected_and_never_published(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $file = UploadedFile::fake()->createWithContent('logo.png', 'not a real image');

        try {
            app(ClubBrandingService::class)->replaceLogo($settings, $file, null);
            $this->fail('Un fichier non décodable par GD aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('image', $e->getMessage());
        }

        $settings->refresh();
        $this->assertNull($settings->logo_path, 'Aucun logo ne doit être enregistré.');
        $this->assertSame([], Storage::disk('public')->allFiles('logos'), 'Rien ne doit être écrit sur le disque public.');
    }

    /**
     * Le SVG est le cas concret qui motive le refus : GD ne le rasterise pas, et il s'exécute dans
     * l'origine de l'application (XSS stocké → vol de session). `X-Content-Type-Options: nosniff`
     * ne protège pas — le fichier est servi avec son vrai type, `image/svg+xml`, qui est exécutable.
     *
     * Le formulaire admin le refuse déjà en amont (règle `image`, qui exclut le SVG en Laravel 13).
     * Ce test verrouille la garde du SERVICE, seule protection si celui-ci est appelé depuis un
     * autre chemin — une commande, un import, un futur écran — sans repasser par cette validation.
     */
    public function test_svg_payload_is_rejected_by_the_service(): void
    {
        Storage::fake('public');
        $settings = ClubSettings::current();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $this->expectException(RuntimeException::class);

        try {
            app(ClubBrandingService::class)->replaceLogo(
                $settings,
                UploadedFile::fake()->createWithContent('logo.svg', $svg),
                null,
            );
        } finally {
            $this->assertSame([], Storage::disk('public')->allFiles('logos'));
        }
    }

    /** Sans logo enregistré, le filigrane et le lockup retombent sur l'image livrée, jamais une 404. */
    public function test_logo_url_falls_back_to_default_when_no_logo(): void
    {
        $this->assertStringContainsString('logo-default.png', ClubSettings::current()->logoUrl());
    }
}
