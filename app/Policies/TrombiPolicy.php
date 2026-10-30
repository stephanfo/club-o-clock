<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Trombi\ConsentType;

// Qui figure, qui consulte, qui voit quoi au trombinoscope (PRD §4.23.5, cadrage §7.18). Un seul
// endroit, interrogé par l'écran, la fiche, la route des photos et les listes d'inscrits. Le
// mineur se dérive de `dob` au moment de la lecture. Contrôle SERVEUR : ce qu'un lecteur ne doit
// pas voir ne sort pas dans son HTML.
class TrombiPolicy
{
    /** @var array<int, bool> consult() par lecteur : la route des photos l'interroge par vignette. */
    private array $consults = [];

    /** Adhérent de la saison : actif, athlète non suspendu, ou coach / admin. */
    public function figures(User $u): bool
    {
        if ($u->anonymized_at !== null || ! $u->is_active) {
            return false;
        }

        return ($u->hasRole('athlete') && ! $u->athlete_access_suspended)
            || $u->hasRole('coach') || $u->hasRole('admin');
    }

    /** Les adhérents de la saison, plus les parents garants d'un adhérent de la saison. */
    public function consult(User $viewer): bool
    {
        return $this->consults[$viewer->id] ??= $this->figures($viewer) || (
            $viewer->anonymized_at === null && $viewer->is_active
            && User::query()->auTrombinoscope()->where('guardian_id', $viewer->id)->exists()
        );
    }

    /** Coachs, admins et bureau toujours en nom complet, comme sur la fiche séance (§4.11.4). */
    public function fullName(User $subject): bool
    {
        return $subject->hasRole('coach') || $subject->hasRole('admin') || $subject->board_role !== null
            || $subject->hasConsent(ConsentType::FullName);
    }

    /** Coachs et admins voient déjà la catégorie dans leurs écrans (§4.23.2). */
    public function category(User $viewer, User $subject): bool
    {
        return $subject->hasConsent(ConsentType::Category)
            || $viewer->hasRole('coach') || $viewer->hasRole('admin');
    }

    public function birthday(User $subject): bool
    {
        return $subject->dob !== null && $subject->hasConsent(ConsentType::Birthday);
    }

    /** Coordonnées : lecteur majeur, intéressé majeur, accord actif. */
    public function email(User $viewer, User $subject): bool
    {
        return $this->contactsVisibleTo($viewer) && ! $subject->isLegallyMinor()
            && $subject->email !== null && $subject->hasConsent(ConsentType::Email);
    }

    public function phone(User $viewer, User $subject): bool
    {
        return $this->contactsVisibleTo($viewer) && ! $subject->isLegallyMinor()
            && $subject->phone !== null && $subject->hasConsent(ConsentType::Phone);
    }

    /** Un adolescent voit les visages et les anniversaires, jamais un email ou un téléphone. */
    public function contactsVisibleTo(User $viewer): bool
    {
        return ! $viewer->isLegallyMinor();
    }

    /**
     * La photo : l'intéressé et son parent garant la voient toujours (masquée, elle reste à leur
     * profil) ; les autres lecteurs, sous accord et si l'intéressé figure. Ni le bureau ni l'admin
     * ne voient une photo masquée.
     */
    public function photo(User $viewer, User $subject): bool
    {
        if ($subject->photo_path === null) {
            return false;
        }
        if ($viewer->id === $subject->id || ($subject->guardian_id !== null && $viewer->id === $subject->guardian_id)) {
            return true;
        }

        return $subject->hasConsent(ConsentType::Photo) && $this->figures($subject) && $this->consult($viewer);
    }
}
