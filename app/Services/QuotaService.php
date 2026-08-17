<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Carbon;

// Quota fair-share par tag (PRD §4.10). Compteur DÉRIVÉ par requête indexée
// (NF §4.10.1 « temps quasi-constant ») — pas de colonne maintenue ni de cache.
// Semaine = lundi 00:00 → dimanche 23:59 dans le fuseau du club (§4.10.1).
class QuotaService
{
    /** Bornes [début, fin] de la semaine contenant $ref, dans le fuseau du club (pour affichage). */
    public function weekBounds(Carbon $ref): array
    {
        $tz = ClubSettings::current()->timezone;
        $local = $ref->copy()->setTimezone($tz);

        return [
            $local->copy()->startOfWeek(Carbon::MONDAY),
            $local->copy()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    /**
     * Mêmes instants que weekBounds, exprimés en UTC pour les comparaisons SQL : start_at est
     * stocké en UTC et Laravel sérialise les Carbon dans LEUR fuseau, sans conversion — des
     * bornes en fuseau club seraient décalées de 1-2 h (séances de lundi 00h-02h perdues).
     */
    public function weekBoundsUtc(Carbon $ref): array
    {
        [$from, $to] = $this->weekBounds($ref);

        return [$from->utc(), $to->utc()];
    }

    /**
     * Nombre d'inscriptions `participating` de $user sur les séances portant $tagId
     * dans la semaine de $ref (futures + passées — check d'inscription §4.10.2).
     */
    public function weeklyCount(User $user, int $tagId, Carbon $ref, ?int $excludeSessionId = null): int
    {
        [$from, $to] = $this->weekBoundsUtc($ref);

        return Registration::query()
            ->where('user_id', $user->id)
            ->where('status', 'participating')
            ->when($excludeSessionId, fn ($q) => $q->where('session_id', '!=', $excludeSessionId))
            ->whereHas('session', fn ($q) => $q
                ->where('quota_tag_id', $tagId)
                ->whereNull('cancelled_at') // une séance annulée ne consomme plus de quota (§4.10.6).
                ->whereBetween('start_at', [$from, $to]))
            ->count();
    }

    /**
     * Usage hebdo par tag ACTIF pour l'affichage (compteurs Profil §4.10.7 et Accueil) : pour
     * chaque tag non archivé, séances `participating` de $user dans la semaine de $ref + titres.
     * Source unique du calcul d'affichage — ne pas dupliquer dans les composants.
     *
     * @return list<array{tag: string, used: int, max: int|null, sessions: list<string>}>
     */
    public function weeklyUsage(User $user, Carbon $ref): array
    {
        [$from, $to] = $this->weekBoundsUtc($ref);

        $byTag = Session::query()
            ->whereNotNull('quota_tag_id')
            ->whereNull('cancelled_at')
            ->whereBetween('start_at', [$from, $to])
            ->whereHas('registrations', fn ($r) => $r
                ->where('user_id', $user->id)
                ->where('status', 'participating'))
            ->orderBy('start_at')
            ->get()
            ->groupBy('quota_tag_id');

        return QuotaTag::query()
            ->whereNull('archived_at')
            ->orderBy('label')
            ->get()
            ->map(function (QuotaTag $tag) use ($byTag) {
                $sessions = $byTag->get($tag->id, collect());

                return [
                    'tag' => $tag->label,
                    'used' => $sessions->count(),
                    'max' => $tag->max_per_week,
                    'sessions' => $sessions->map(fn (Session $s) => $s->title)->all(),
                ];
            })
            ->all();
    }

    /** L'athlète a-t-il atteint son quota pour le tag de cette séance cette semaine-là ? */
    public function isOverQuota(User $user, Session $session, ?int $excludeSessionId = null): bool
    {
        if ($session->quota_tag_id === null) {
            return false; // séance sans tag → pas de quota (§4.10.3).
        }

        $max = $session->loadMissing('quotaTag')->quotaTag?->max_per_week ?? 1;

        return $this->weeklyCount($user, $session->quota_tag_id, $session->start_at, $excludeSessionId) >= $max;
    }
}
