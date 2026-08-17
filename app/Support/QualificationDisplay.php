<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Affichage des qualifications coach (PRD §4.11.3 / §4.11.4) : statut d'expiration (badges) et
// agrégation dédupliquée des qualifs des encadrants pour le bloc « Qualifications disponibles ».
class QualificationDisplay
{
    /** Seuil « expire bientôt » : alerte douce sous 30 jours (§4.11.3). */
    public const SOON_DAYS = 30;

    /**
     * Statut d'une qualif d'après expiresAt (§4.11.3).
     * Retourne ['status' => none|valid|soon|expired, 'cls' => classe chip, 'expires_at' => Carbon?].
     *
     * @return array{status:string,cls:string,expires_at:?Carbon}
     */
    public static function status(?Carbon $expiresAt, ?Carbon $now = null): array
    {
        if ($expiresAt === null) {
            return ['status' => 'none', 'cls' => 'chip-line', 'expires_at' => null];
        }

        $now ??= Carbon::now();

        if ($expiresAt->isBefore($now->copy()->startOfDay())) {
            return ['status' => 'expired', 'cls' => 'chip-danger', 'expires_at' => $expiresAt];
        }

        if ($expiresAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) <= self::SOON_DAYS) {
            return ['status' => 'soon', 'cls' => 'chip-warn', 'expires_at' => $expiresAt];
        }

        return ['status' => 'valid', 'cls' => 'chip-green', 'expires_at' => $expiresAt];
    }

    /**
     * Agrège les qualifs des coachs encadrants en chips uniques (déduplication par
     * qualificationId — §4.11.4). Pour chaque qualif : le label, et la liste des coachs qui la
     * portent avec leur statut d'expiration (pour le détail au tap/hover).
     *
     * @param  Collection<int, User>  $coaches  encadrants, avec qualifications chargées.
     * @return Collection<int, array{id:int,label:string,code:?string,holders:array<int,array{name:string,status:array}>,worst:string}>
     */
    public static function aggregate(Collection $coaches, ?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();
        $byQualif = [];

        foreach ($coaches as $coach) {
            foreach ($coach->qualifications as $qualif) {
                $expires = $qualif->pivot->expires_at ? Carbon::parse($qualif->pivot->expires_at) : null;
                $status = self::status($expires, $now);

                $byQualif[$qualif->id] ??= [
                    'id' => $qualif->id,
                    'label' => $qualif->label,
                    'code' => $qualif->code,
                    'holders' => [],
                    'worst' => 'none',
                ];
                $byQualif[$qualif->id]['holders'][] = [
                    'name' => $coach->fullName(),
                    'status' => $status,
                ];
                $byQualif[$qualif->id]['worst'] = self::worst($byQualif[$qualif->id]['worst'], $status['status']);
            }
        }

        return collect($byQualif)->sortBy('label')->values();
    }

    /** Statut le plus alarmant entre deux (pour colorer le chip agrégé). */
    private static function worst(string $a, string $b): string
    {
        $rank = ['none' => 0, 'valid' => 1, 'soon' => 2, 'expired' => 3];

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    /** Classe chip à appliquer au chip agrégé d'après le pire statut parmi les porteurs. */
    public static function clsFor(string $worst): string
    {
        return match ($worst) {
            'expired' => 'chip-danger',
            'soon' => 'chip-warn',
            default => 'chip-ink',
        };
    }
}
