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

    /**
     * Dimensions exigées et aplatissement, par variante d'icône PWA (cadrage §7.16).
     *
     * Les dimensions sont vérifiées EXACTEMENT plutôt que redimensionnées : une icône hors format
     * casse l'installation PWA sans la moindre erreur visible, mieux vaut refuser à l'upload que
     * livrer une PWA silencieusement cassée. `opaque` ne concerne que l'icône iOS : iOS rend toute
     * transparence résiduelle en NOIR, alors que les formats manifest, déclarés `any maskable`,
     * gardent leur canal alpha.
     *
     * @var array<string, array{size: int, opaque: bool}>
     */
    private const ICON_SPECS = [
        'icon_192' => ['size' => 192, 'opaque' => false],
        'icon_512' => ['size' => 512, 'opaque' => false],
        'icon_apple' => ['size' => 180, 'opaque' => true],
    ];

    /** Fond d'aplatissement de l'icône iOS : le `background_color` du manifest, pas la couleur du club. */
    private const ICON_FLATTEN_RGB = [255, 255, 255];

    /**
     * Remplace UNE icône PWA du club. $variant est une clé de ClubSettings::PWA_ICONS.
     *
     * Même barrière que le logo : seul ce que GD sait décoder est publié sous une URL same-origin.
     */
    public function replacePwaIcon(ClubSettings $settings, string $variant, UploadedFile $file, ?User $actor): ClubSettings
    {
        $spec = self::ICON_SPECS[$variant] ?? throw new RuntimeException("Icône PWA inconnue : {$variant}.");
        $column = ClubSettings::PWA_ICONS[$variant][0];

        $decoded = $this->decode($file->getRealPath());
        if ($decoded === null) {
            throw new RuntimeException("Le fichier déposé n'est pas une image exploitable.");
        }

        if (imagesx($decoded) !== $spec['size'] || imagesy($decoded) !== $spec['size']) {
            throw new RuntimeException("L'icône doit faire exactement {$spec['size']}×{$spec['size']} pixels.");
        }

        $old = $settings->{$column};
        $dir = 'icons/'.Str::random(24);
        $path = "{$dir}/{$variant}.png";

        // Ré-encodage systématique (jamais le fichier d'origine) : ce qui est publié est alors le
        // rendu de GD, donc débarrassé de toute charge annexe qu'un PNG peut transporter.
        $image = $spec['opaque'] ? $this->flatten($decoded, $spec['size']) : $decoded;

        Storage::disk('public')->put($path, $this->encodePng($image, ! $spec['opaque']));

        $settings->update([$column => $path]);

        if ($old) {
            $this->deleteIconDirectory($old);
        }

        AuditLogger::record('club_pwa_icon_updated', $actor, ['variant' => $variant]);

        return $settings;
    }

    /** Rétablit le jeu d'icônes livré : efface les fichiers du club et remet les colonnes à NULL. */
    public function resetPwaIcons(ClubSettings $settings, ?User $actor): ClubSettings
    {
        $cleared = [];

        foreach (ClubSettings::PWA_ICONS as $variant => [$column, $fallback]) {
            if ($settings->{$column}) {
                $this->deleteIconDirectory($settings->{$column});
                $cleared[] = $variant;
            }
            $settings->{$column} = null;
        }

        if ($cleared === []) {
            return $settings;
        }

        $settings->save();

        AuditLogger::record('club_pwa_icons_reset', $actor, ['variants' => $cleared]);

        return $settings;
    }

    /** Aplatit sur fond opaque (iOS rend l'alpha en noir), en conservant le carré d'origine. */
    private function flatten(GdImage $source, int $size): GdImage
    {
        $flat = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = self::ICON_FLATTEN_RGB;
        imagefilledrectangle($flat, 0, 0, $size, $size, (int) imagecolorallocate($flat, $r, $g, $b));

        // Alpha blending ACTIF : la source se compose sur le fond au lieu de l'écraser.
        imagealphablending($flat, true);
        imagecopy($flat, $source, 0, 0, 0, 0, $size, $size);

        return $flat;
    }

    /** Supprime le dossier d'une icône remplacée (un dossier aléatoire par téléversement). */
    private function deleteIconDirectory(string $oldPath): void
    {
        $dir = dirname($oldPath);
        if ($dir !== '.' && $dir !== 'icons') {
            Storage::disk('public')->deleteDirectory($dir);
        }
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

    private function encodePng(GdImage $image, bool $withAlpha = true): string
    {
        // Sans ce couple, GD aplatit le canal alpha à l'encodage : les vignettes du logo et les
        // icônes `maskable` doivent le conserver. L'icône iOS, déjà aplatie, s'en passe.
        if ($withAlpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

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
