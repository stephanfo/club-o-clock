<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sujet consulté par un parent garant (PRD §4.2, proto shell.jsx SubjectSwitcher).
 * Le parent reste LUI-MÊME connecté (jamais d'impersonation) ; le « sujet » est la personne
 * dont on consulte/gère les activités : soi, ou un enfant garanti. Persisté en session,
 * il pilote l'Accueil, le Planning et l'inscription depuis la fiche — pas « Moi ».
 *
 * Les Alertes ne le pilotent pas mais le REFLÈTENT : une notification concernant un enfant porte
 * son prénom et son lien profond pose le sujet via `?as=` (§4.15.5). Sans quoi un parent lui-même
 * athlète ne pouvait pas distinguer ses notifications de celles de ses enfants.
 */
class SubjectContext
{
    private const SESSION_KEY = 'subject_id';

    /** Enfants garantis consultables : actifs, non anonymisés. @return Collection<int, User> */
    public static function wards(User $user): Collection
    {
        // categories préchargé : les consommateurs (cartes enfants, SessionPolicy via le sujet)
        // lisent primaryCategory()/hasActiveCategory() qui s'appuient sur la relation.
        return $user->wards()
            ->with('categories')
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->orderBy('first_name')
            ->get();
    }

    public static function isGuardian(User $user): bool
    {
        return self::wards($user)->isNotEmpty();
    }

    /** Sujet courant : l'enfant sélectionné en session s'il est toujours garanti, sinon soi. */
    public static function current(User $user): User
    {
        $id = session(self::SESSION_KEY);
        if ($id === null || $id === $user->id) {
            return $user;
        }

        return self::wards($user)->firstWhere('id', $id) ?? $user;
    }

    /** Sélectionne un sujet (null / son propre id = soi). Ignore un id non garanti. */
    public static function set(User $user, ?int $id): void
    {
        if ($id === null || $id === $user->id || self::wards($user)->contains('id', $id)) {
            session([self::SESSION_KEY => $id === $user->id ? null : $id]);
        }
    }

    /** Prénom du sujet si ce n'est pas soi (libellés « Hugo participe », « Inscrire Hugo »). */
    public static function firstNameIfChild(User $user, ?User $subject = null): ?string
    {
        $subject ??= self::current($user);

        return $subject->id === $user->id ? null : $subject->first_name;
    }

    /** Phase du cycle mineur (§4.2) : P1 = pas de credential (email nul), P2 = compte propre. */
    public static function phase(User $ward): string
    {
        return $ward->email === null ? 'P1' : 'P2';
    }
}
