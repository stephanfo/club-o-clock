<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\TrombiService;
use App\Support\Trombi\ConsentType;
use App\Support\Trombi\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

// Photo et téléphone du trombinoscope (#72, PRD §4.23.2, cadrage §7.18).
class TrombiDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): TrombiService
    {
        return app(TrombiService::class);
    }

    private function tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'trombi');
        file_put_contents($path, $bytes);

        return $path;
    }

    /** JPEG paysage 400×200 : moitié gauche rouge, droite bleue. */
    private function jpeg(?string $exif = null): string
    {
        $img = imagecreatetruecolor(400, 200);
        imagefilledrectangle($img, 0, 0, 199, 199, (int) imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 199, (int) imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();

        // Segment APP1 inséré juste après SOI.
        return $exif === null ? $jpeg : substr($jpeg, 0, 2).$exif.substr($jpeg, 2);
    }

    /** APP1 Exif : Orientation = 6 (90° horaire) et une description repérable dans le fichier. */
    private function exifSegment(): string
    {
        $secret = "GPS-SECRET-48.8566\0"; // 19 octets
        $ifd = pack('n', 2)
            .pack('nnNnn', 0x0112, 3, 1, 6, 0)                  // Orientation
            .pack('nnNN', 0x010E, 2, strlen($secret), 8 + 2 + 24 + 4) // ImageDescription
            .pack('N', 0);
        $tiff = 'MM'.pack('nN', 42, 8).$ifd.$secret;
        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    private function decoded(User $u, int $size): \GdImage
    {
        $bytes = Storage::disk('local')->get(TrombiService::photoFile($u, $size));
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($bytes)[2]);

        return imagecreatefromstring($bytes);
    }

    public function test_photo_redressee_recadree_reencodee_sans_metadonnees(): void
    {
        $u = User::factory()->create();
        $file = $this->tmp($this->jpeg($this->exifSegment()));
        $this->assertStringContainsString('GPS-SECRET', (string) file_get_contents($file), 'Contrôle positif : le marqueur est bien dans l\'original.');
        $this->assertSame(6, exif_read_data($file)['Orientation'] ?? null, 'Contrôle positif : l\'EXIF est lisible.');

        $this->service()->storePhoto($u, $file, $u);

        $u->refresh();
        $this->assertNotNull($u->photo_path);
        foreach (TrombiService::SIZES as $size) {
            $img = $this->decoded($u, $size);
            $this->assertSame([$size, $size], [imagesx($img), imagesy($img)]);
            $this->assertStringNotContainsString('GPS-SECRET', Storage::disk('local')->get(TrombiService::photoFile($u, $size)));
        }

        // Orientation 6 : la moitié gauche (rouge) passe en haut.
        $img = $this->decoded($u, 192);
        $top = imagecolorsforindex($img, imagecolorat($img, 40, 8));
        $bottom = imagecolorsforindex($img, imagecolorat($img, 40, 184));
        $this->assertGreaterThan($top['blue'], $top['red']);
        $this->assertGreaterThan($bottom['red'], $bottom['blue']);
        $this->assertCount(2, Storage::disk('local')->allFiles('trombi'), 'L\'original n\'est pas conservé.');
    }

    public function test_formats_et_tailles_refuses(): void
    {
        $u = User::factory()->create();

        // GIF, texte, plus de 5 Mo.
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $cases = [(string) ob_get_clean(), '<svg xmlns="http://www.w3.org/2000/svg"/>', str_repeat('a', TrombiService::MAX_BYTES + 1)];

        // PNG dont l'en-tête annonce 8000×6000 (48 Mpx) : refusé sur l'en-tête, avant décodage.
        $png = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $cases[] = substr($bytes, 0, 16).pack('NN', 8000, 6000).substr($bytes, 24);

        foreach ($cases as $i => $bytes) {
            try {
                $this->service()->storePhoto($u, $this->tmp($bytes), $u);
                $this->fail("Cas {$i} accepté.");
            } catch (RuntimeException) {
            }
        }
        $this->assertNull($u->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('trombi'));
    }

    public function test_remplacer_efface_l_ancienne_et_renouvelle_le_nom(): void
    {
        $u = User::factory()->create();
        $this->service()->storePhoto($u, $this->tmp($this->jpeg()), $u);
        $first = $u->fresh()->photo_path;

        $this->service()->storePhoto($u, $this->tmp($this->jpeg()), $u);

        $second = $u->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        $this->assertSame(
            [TrombiService::photoFile($u->fresh(), 192), TrombiService::photoFile($u->fresh(), 512)],
            Storage::disk('local')->allFiles('trombi')
        );
    }

    public function test_seuls_l_interesse_et_le_parent_deposent_jamais_l_admin(): void
    {
        $u = User::factory()->create();
        $parent = User::factory()->create();
        $ado = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'email' => 'ado@exemple.test']);

        foreach ([[$u, User::factory()->admin()->create()], [$u, User::factory()->create()], [$ado, $ado]] as [$subject, $actor]) {
            try {
                $this->service()->storePhoto($subject, $this->tmp($this->jpeg()), $actor);
                $this->fail('Dépôt par un tiers accepté.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles('trombi'));

        $this->service()->storePhoto($ado, $this->tmp($this->jpeg()), $parent);
        $this->assertNotNull($ado->fresh()->photo_path);

        // L'ado supprime sa photo lui-même : supprimer protège toujours.
        $this->service()->deletePhoto($ado, $ado);
        $this->assertNull($ado->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('trombi'));
    }

    public function test_retirer_l_accord_garde_la_photo_supprimer_l_efface(): void
    {
        $u = User::factory()->create();
        $this->service()->storePhoto($u, $this->tmp($this->jpeg()), $u);
        app(ConsentService::class)->grant($u, ConsentType::Photo, $u);

        app(ConsentService::class)->revoke($u, ConsentType::Photo, $u);
        $this->assertNotNull($u->fresh()->photo_path, 'Retirer masque, ne supprime pas.');

        $this->service()->deletePhoto($u, $u);
        $this->assertNull($u->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('trombi'));
    }

    public function test_l_admin_modere_une_photo(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->service()->storePhoto($u, $this->tmp($this->jpeg()), $u);
        app(ConsentService::class)->grant($u, ConsentType::Photo, $u);

        try {
            $this->service()->removePhotoByAdmin($u, User::factory()->coach()->create());
            $this->fail('Modération par un non-admin acceptée.');
        } catch (RuntimeException) {
        }
        $this->assertNotNull($u->fresh()->photo_path);

        $this->service()->removePhotoByAdmin($u, $admin);

        $fresh = $u->fresh();
        $this->assertNull($fresh->photo_path);
        $this->assertFalse($fresh->hasConsent(ConsentType::Photo));
        $this->assertSame([], Storage::disk('local')->allFiles('trombi'));
        $this->assertSame(1, AuditLog::query()->where('action', 'trombi_photo_removed')->where('target_id', $u->id)->count());
    }

    public function test_normalisation_du_telephone(): void
    {
        $this->assertSame('+33612345678', PhoneNumber::normalize('06 12 34 56 78'));
        $this->assertSame('+33612345678', PhoneNumber::normalize('06.12.34.56.78'));
        $this->assertSame('+33612345678', PhoneNumber::normalize('+33 6 12 34 56 78'));
        $this->assertSame('+442079460958', PhoneNumber::normalize('0044 20 7946 0958'));
        foreach (['', '12345', '06 12 34 56', 'appelle-moi', '+0612345678', '0012', '06 12 34 56 78 90'] as $bad) {
            $this->assertNull(PhoneNumber::normalize($bad), $bad);
        }
        $this->assertSame('06 12 34 56 78', PhoneNumber::format('+33612345678'));
        $this->assertSame('+44 20 79 46 09 58', PhoneNumber::format('+442079460958'));
    }

    public function test_telephone_saisi_par_l_interesse_majeur_seul(): void
    {
        $u = User::factory()->create();
        $this->service()->setPhone($u, '06 12 34 56 78', $u);
        $this->assertSame('+33612345678', $u->fresh()->phone);

        $parent = User::factory()->create();
        $child = User::factory()->minorP1()->create(['guardian_id' => $parent->id]);
        foreach ([[$u, User::factory()->admin()->create(), '0611111111'], [$child, $parent, '0611111111'], [$u, $u, 'pas un numéro']] as [$subject, $actor, $raw]) {
            try {
                $this->service()->setPhone($subject, $raw, $actor);
                $this->fail('Saisie refusée acceptée.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('+33612345678', $u->fresh()->phone);
        $this->assertNull($child->fresh()->phone);

        $this->service()->setPhone($u, '  ', $u);
        $this->assertNull($u->fresh()->phone);
    }

    public function test_supprimer_le_numero_retire_son_accord(): void
    {
        $u = User::factory()->create();
        $this->service()->setPhone($u, '06 12 34 56 78', $u);
        app(ConsentService::class)->grant($u, ConsentType::Phone, $u);

        $this->service()->deletePhone($u, $u);
        $this->service()->setPhone($u, '07 98 76 54 32', $u);

        // Le nouveau numéro n'est montré qu'après un nouvel accord.
        $this->assertSame('+33798765432', $u->fresh()->phone);
        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Phone));
    }

    public function test_purge_et_effacement(): void
    {
        $u = User::factory()->create();
        $this->service()->storePhoto($u, $this->tmp($this->jpeg()), $u);
        $this->service()->setPhone($u, '0612345678', $u);
        app(ConsentService::class)->setAll($u, true, $u);

        $this->service()->purge($u, null);

        $fresh = $u->fresh();
        $this->assertNull($fresh->photo_path);
        $this->assertNull($fresh->phone);
        $this->assertSame(0, Consent::query()->whereNull('revoked_at')->count());
        $this->assertSame(6, Consent::count(), 'Purge : les lignes restent comme preuve.');

        $this->service()->erase($fresh);
        $this->assertSame(0, Consent::count(), 'Suppression du compte : le registre part avec lui.');
    }
}
