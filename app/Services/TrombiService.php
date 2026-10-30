<?php

namespace App\Services;

use App\Models\Consent;
use App\Models\User;
use App\Support\Logging\AuditLogger;
use App\Support\Trombi\ConsentType;
use App\Support\Trombi\PhoneNumber;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// Données du trombinoscope (PRD §4.23.2, cadrage §7.18) : photo et téléphone, écrits par
// l'intéressé seul (son parent garant pour un enfant), JAMAIS par l'admin. Stockés indépendamment
// de l'accord : l'accord décide de l'affichage aux autres, la suppression efface. Un fichier ne
// part qu'après le commit, pour qu'un rollback ne laisse pas une ligne pointant vers le vide.
class TrombiService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Au-delà, refus AVANT décodage : un JPEG de 5 Mo peut se décompresser en centaines de Mo. */
    public const MAX_PIXELS = 40_000_000;

    /** Grille (192) et fiche (512), en WebP. */
    public const SIZES = [192, 512];

    private const DISK = 'local';

    public function __construct(private ConsentService $consents) {}

    /** $actor peut-il déposer une photo ou un numéro pour $subject ? */
    public function canEdit(User $actor, User $subject): bool
    {
        if ($subject->anonymized_at !== null) {
            return false;
        }

        return $subject->isLegallyMinor()
            ? $subject->guardian_id !== null && $actor->id === $subject->guardian_id
            : $actor->id === $subject->id;
    }

    /** Supprimer protège toujours : le mineur peut effacer sa propre photo, comme retirer un accord. */
    public function canDelete(User $actor, User $subject): bool
    {
        return $this->canEdit($actor, $subject) || ($subject->anonymized_at === null && $actor->id === $subject->id);
    }

    /** Chemin disque d'une taille de la photo, ou null sans photo. */
    public static function photoFile(User $user, int $size): ?string
    {
        return $user->photo_path === null || ! in_array($size, self::SIZES, true)
            ? null
            : "{$user->photo_path}-{$size}.webp";
    }

    /**
     * Dépose (ou remplace) la photo : recadrée au carré, réencodée en WebP par GD. Le réencodage
     * ne recopie AUCUNE métadonnée — l'EXIF, géolocalisation comprise, disparaît par construction.
     * L'original n'est jamais conservé.
     */
    public function storePhoto(User $subject, string $file, User $actor): void
    {
        if (! $this->canEdit($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas déposer de photo pour ce compte.');
        }

        $encoded = $this->process($file);
        $base = 'trombi/'.Str::random(40);
        foreach ($encoded as $size => $bytes) {
            Storage::disk(self::DISK)->put("{$base}-{$size}.webp", $bytes);
        }

        try {
            DB::transaction(function () use ($subject, $base) {
                $locked = User::query()->lockForUpdate()->findOrFail($subject->getKey());
                $old = $locked->photo_path;
                $locked->forceFill(['photo_path' => $base])->save();
                if ($old !== null) {
                    DB::afterCommit(fn () => $this->deleteFiles($old));
                }
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($base);
            throw $e;
        }

        $subject->forceFill(['photo_path' => $base])->syncOriginalAttribute('photo_path');
    }

    public function deletePhoto(User $subject, User $actor): void
    {
        if (! $this->canDelete($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas supprimer cette photo.');
        }

        DB::transaction(fn () => $this->clearPhoto($subject));
    }

    /**
     * Modération (§4.23.1) : l'admin efface une photo inappropriée — suppression, pas masquage —,
     * ce qui révoque aussi l'accord photo.
     */
    public function removePhotoByAdmin(User $subject, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new RuntimeException('Réservé à l\'admin.');
        }

        DB::transaction(function () use ($subject, $admin) {
            if (! $this->clearPhoto($subject)) {
                return;
            }
            $this->consents->revokeType($subject, ConsentType::Photo, $admin);
            AuditLogger::record('trombi_photo_removed', $admin, [
                'target_type' => User::class,
                'target_id' => $subject->id,
            ]);
        });
    }

    /** Saisie du numéro ; vide = suppression. */
    public function setPhone(User $subject, string $raw, User $actor): void
    {
        if (trim($raw) === '') {
            $this->deletePhone($subject, $actor);

            return;
        }
        if (! $this->canEdit($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas saisir de numéro pour ce compte.');
        }
        if ($subject->isLegallyMinor()) {
            throw new RuntimeException('Un mineur ne partage pas de numéro de téléphone.');
        }
        $phone = PhoneNumber::normalize($raw);
        if ($phone === null) {
            throw new RuntimeException('Ce numéro n\'est pas valide (ex. 06 12 34 56 78 ou +44 20 7946 0958).');
        }

        $subject->forceFill(['phone' => $phone])->save();
    }

    public function deletePhone(User $subject, User $actor): void
    {
        if (! $this->canDelete($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas supprimer ce numéro.');
        }

        $subject->forceFill(['phone' => null])->save();
    }

    /**
     * Purge de bascule de saison (§4.4, §4.23.1) : qui n'a pas réadhéré de toute la saison écoulée
     * perd photo et numéro, et ses accords sont révoqués (les lignes restent comme preuve).
     */
    public function purge(User $subject, ?User $actor): void
    {
        DB::transaction(function () use ($subject, $actor) {
            $this->clearPhoto($subject);
            $subject->forceFill(['phone' => null])->save();
            $this->consents->revokeAll($subject, $actor);
        });
    }

    /** Suppression du compte (§4.3) : fichier, numéro ET registre partent. À appeler en transaction. */
    public function erase(User $subject): void
    {
        $this->clearPhoto($subject);
        $subject->forceFill(['phone' => null, 'board_role' => null])->save();
        Consent::query()->where('user_id', $subject->id)->delete();
        $subject->unsetRelation('unrevokedConsents');
    }

    /** @return bool true si une photo existait */
    private function clearPhoto(User $subject): bool
    {
        $locked = User::query()->lockForUpdate()->findOrFail($subject->getKey());
        $old = $locked->photo_path;
        $subject->forceFill(['photo_path' => null])->syncOriginalAttribute('photo_path');
        if ($old === null) {
            return false;
        }

        $locked->forceFill(['photo_path' => null])->save();
        DB::afterCommit(fn () => $this->deleteFiles($old));

        return true;
    }

    private function deleteFiles(string $base): void
    {
        Storage::disk(self::DISK)->delete(array_map(fn (int $s) => "{$base}-{$s}.webp", self::SIZES));
    }

    /**
     * Contrôle, décode, redresse, recadre et réencode.
     *
     * @return array<int, string> [taille => octets WebP]
     */
    private function process(string $file): array
    {
        $bytes = @filesize($file);
        if ($bytes === false || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('La photo dépasse 5 Mo.');
        }

        $info = @getimagesize($file);
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Formats acceptés : JPEG, PNG ou WebP.');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('Cette photo est trop grande (40 mégapixels au plus).');
        }

        $this->ensureMemory($width * $height);

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
        };
        if (! $image instanceof GdImage) {
            throw new RuntimeException('La photo n\'a pas pu être lue.');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $file);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $srcX = intdiv($w - $side, 2);
        // Portrait : calé sur le tiers supérieur, où se trouve le visage.
        $srcY = intdiv($h - $side, 3);

        $out = [];
        foreach (self::SIZES as $size) {
            $thumb = imagecreatetruecolor($size, $size);
            // Fond blanc sous un PNG transparent : le WebP opaque pèse moins et s'affiche partout pareil.
            imagefill($thumb, 0, 0, (int) imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
            ob_start();
            imagewebp($thumb, null, 82);
            $out[$size] = (string) ob_get_clean();
        }

        return $out;
    }

    /**
     * Rotation selon l'orientation EXIF (photos de téléphone). Sans l'extension exif, la photo
     * garde l'orientation de ses pixels.
     */
    private function orient(GdImage $image, string $file): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    /**
     * GD tient l'image décodée en mémoire, ~5 octets par pixel, plus la copie d'une rotation :
     * une photo de téléphone de 24 Mpx dépasse le `memory_limit` courant du mutualisé (128 Mo).
     * On relève la limite pour ce traitement seulement, sans dépasser 512 Mo.
     */
    private function ensureMemory(int $pixels): void
    {
        $needed = $pixels * 10 + 32 * 1024 * 1024;
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < $needed) {
            @ini_set('memory_limit', (string) min($needed, 512 * 1024 * 1024));
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
