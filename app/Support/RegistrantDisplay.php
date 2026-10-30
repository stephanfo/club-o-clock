<?php

namespace App\Support;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Support\Trombi\TrombiCard;
use Illuminate\Support\Collection;

// Affichage RGPD des noms d'inscrits (PRD §4.9.4).
// Entre athlètes : prénom + initiale, étendue au minimum nécessaire pour lever
// l'homonymie sur une même séance (« Marc Sa. » / « Marc Sm. »), ou nom complet pour qui l'a
// accepté au trombinoscope (§4.23.1, TrombiPolicy::fullName). Coachs/admins : nom complet.
// Relation à charger sur chaque utilisateur : `unrevokedConsents`.
class RegistrantDisplay
{
    /**
     * Construit la table [user_id => libellé] pour un ensemble d'utilisateurs sur une séance.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, string>
     */
    public static function labels(Collection $users, bool $fullNames): array
    {
        $users = $users->filter()->unique('id')->values();

        if ($fullNames) {
            return $users->mapWithKeys(fn (User $u) => [
                $u->id => trim($u->first_name.' '.$u->last_name),
            ])->all();
        }

        // Entre athlètes : prénom + initiale du nom, étendue par groupe d'homonymes, sauf accord.
        $policy = new TrombiPolicy;
        $labels = [];
        foreach ($users->groupBy(fn (User $u) => mb_strtolower($u->first_name)) as $group) {
            foreach ($group as $user) {
                $labels[$user->id] = $policy->fullName($user)
                    ? $user->fullName()
                    : trim($user->first_name.' '.self::initials($user, $group));
            }
        }

        return $labels;
    }

    /**
     * Table [user_id => URL de la vignette] des photos que $viewer peut voir (§4.23.5).
     *
     * @param  Collection<int, User>  $users
     * @return array<int, string>
     */
    public static function photos(Collection $users, ?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }
        $policy = app(TrombiPolicy::class);

        return $users->filter()->unique('id')
            ->mapWithKeys(fn (User $u) => [$u->id => TrombiCard::photoUrl($viewer, $u, 192, $policy)])
            ->filter()->all();
    }

    /**
     * Initiale(s) du nom, étendue(s) jusqu'à différencier $user des autres prénoms identiques.
     *
     * @param  Collection<int, User>  $sameFirstName
     */
    private static function initials(User $user, Collection $sameFirstName): string
    {
        $last = $user->last_name ?? '';
        if ($last === '' || $sameFirstName->count() === 1) {
            return $last === '' ? '' : mb_substr($last, 0, 1).'.';
        }

        $others = $sameFirstName->where('id', '!=', $user->id)->pluck('last_name');

        // Étend l'initiale jusqu'à ce que le préfixe soit unique parmi les homonymes.
        for ($len = 1; $len <= mb_strlen($last); $len++) {
            $prefix = mb_strtolower(mb_substr($last, 0, $len));
            $collision = $others->contains(fn (?string $o) => mb_strtolower(mb_substr((string) $o, 0, $len)) === $prefix);
            if (! $collision) {
                return mb_substr($last, 0, $len).'.';
            }
        }

        // Préfixes identiques sur toute la longueur disponible → nom complet.
        return $last;
    }
}
