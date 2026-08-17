<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\User;
use App\Support\Logging\AuditLogger;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// Point de passage unique pour l'upload/remplacement du logo du club (plan open source OS2).
// Disque `public` (contrairement aux GPX bruts, hors webroot) : le logo est un asset public,
// servi via storage:link.
class ClubBrandingService
{
    /**
     * Tailles de vignette générées à l'upload (carré, recadré centré). Un fichier uploadé par un
     * admin (photo, export web) n'est pas conçu pour un downscale navigateur propre à 24-30px
     * (contrairement à l'ancien pictogramme fixe du design d'origine) : on rééchantillonne une
     * fois, proprement (GD, imagecopyresampled = bicubique-like), plutôt que de laisser chaque
     * écran réduire l'original à la volée.
     *
     * @var array<int, string>
     */
    private const THUMB_SIZES = [64 => 'thumb-64', 128 => 'thumb-128'];

    public function replaceLogo(ClubSettings $settings, UploadedFile $file, ?User $actor): ClubSettings
    {
        // Seconde barrière, après la liste blanche `mimes` du formulaire : on ne publie sur le
        // disque `public` (servi same-origin) que ce que GD a su décoder comme image matricielle.
        // Un fichier que GD refuse est soit corrompu, soit un document exécutable déguisé (SVG,
        // HTML renommé) — dans les deux cas il n'a rien à faire sous une URL de l'application.
        $decoded = $this->decode($file->getRealPath());
        if ($decoded === null) {
            throw new RuntimeException("Le fichier déposé n'est pas une image exploitable.");
        }

        $old = $settings->logo_path;
        $dir = 'logos/'.Str::random(24);

        $originalPath = $file->storeAs($dir, 'original.'.$file->extension(), 'public');
        if ($originalPath === false) {
            throw new RuntimeException("Le logo n'a pas pu être stocké.");
        }

        $this->generateThumbnails($decoded, $dir);

        $settings->update(['logo_path' => $originalPath]);

        if ($old) {
            $this->deleteLogoDirectory($old);
        }

        AuditLogger::record('club_logo_updated', $actor, []);

        return $settings;
    }

    /** Décode l'image avec GD, ou null si le fichier n'est pas une image matricielle exploitable. */
    private function decode(string $sourcePath): ?GdImage
    {
        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image === false ? null : $image;
    }

    /** Génère les vignettes carrées (recadrage centré + rééchantillonnage) à côté de l'original. */
    private function generateThumbnails(GdImage $source, string $dir): void
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = (int) (($width - $side) / 2);
        $srcY = (int) (($height - $side) / 2);

        foreach (self::THUMB_SIZES as $size => $filename) {
            $thumb = imagecreatetruecolor($size, $size);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $size, $size, $transparent);

            imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

            Storage::disk('public')->put("{$dir}/{$filename}.png", $this->encodePng($thumb));
        }
    }

    private function encodePng(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Supprime le dossier complet (original + vignettes) de l'ancien logo. */
    private function deleteLogoDirectory(string $oldOriginalPath): void
    {
        $dir = dirname($oldOriginalPath);
        if ($dir !== '.' && $dir !== 'logos') {
            Storage::disk('public')->deleteDirectory($dir);
        }
    }
}
