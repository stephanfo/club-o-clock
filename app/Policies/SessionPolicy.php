<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;

// Contrôle d'accès Session (PRD §4.7). Invariant J0 : Policy à la naissance de l'entité (ROADMAP_DEV §26).
class SessionPolicy
{
    /** Le planning est visible à tout membre connecté (le ciblage catégorie filtre, n'interdit pas — §4.5). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Session $session): bool
    {
        return true;
    }

    /** Création standalone : coachs et admins (§4.7). */
    public function create(User $user): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /** Édition : coach ou admin (§4.7 « n'importe quel coach + admin »). */
    public function update(User $user, Session $session): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /** Annulation : coach ou admin (§4.7). */
    public function cancel(User $user, Session $session): bool
    {
        return $this->update($user, $session);
    }

    /** Restauration : coach/admin ET tant que startAt n'est pas dépassé (§4.7 garde-fou). */
    public function restore(User $user, Session $session): bool
    {
        return $this->update($user, $session) && $session->start_at->isFuture();
    }

    public function delete(User $user, Session $session): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * S'inscrire à une séance (§4.9). Soi-même, OU son enfant garanti (invariant
     * parent → Registration enfant, ancré dès J2 — ROADMAP_DEV §26).
     * Le self-service est bloqué si l'accès athlète de la cible est suspendu
     * (le bypass coach via override = J3). $target null = pour soi.
     *
     * Garde catégorielle (§4.5, défense en profondeur) : la cible doit avoir une catégorie active
     * ET la séance doit cibler l'une d'elles. La fiche restant atteignable par URL directe (view =
     * public), ce contrôle empêche l'inscription hors catégorie que la seule visibilité planning ne
     * bloquerait pas. Une inscription active déjà en place est grandfathered (§4.5 l.262) : on ne
     * bloque pas une désinscription/re-inscription d'un athlète déjà engagé. Le staff n'emprunte pas
     * ce chemin (enrollOther / override §4.10.5).
     */
    public function enroll(User $user, Session $session, ?User $target = null): bool
    {
        $target ??= $user;

        if (! $this->actsForTarget($user, $target)) {
            return false;
        }

        if ($target->athlete_access_suspended) {
            return false;
        }

        // Déjà inscrit·e activement → grandfathered : l'action reste permise (désinscription incluse).
        $alreadyActive = $session->registrations
            ->first(fn ($r) => $r->user_id === $target->id && $r->status !== 'cancelled') !== null;
        if ($alreadyActive) {
            return true;
        }

        return $target->hasActiveCategory() && $target->isTargetedBy($session);
    }

    /** Se désinscrire (§4.9) : soi-même ou son enfant garanti. $target null = pour soi. */
    public function unenroll(User $user, Session $session, ?User $target = null): bool
    {
        $target ??= $user;

        return $this->actsForTarget($user, $target);
    }

    /**
     * Inscrire un athlète tiers (§4.9.7 « inscription par un coach »). Coach + admin (le PRD
     * emploie « coach » au sens staff : cf. §4.11 droits opérationnels, override, mécanisme C, tous
     * coach+admin). Distinct de enroll() (self / parent→enfant). Le bypass d'un compte suspendu
     * passe par l'override §4.10.5 dans le service, pas par cette garde.
     */
    public function enrollOther(User $user, Session $session): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /** Retirer un athlète tiers de la séance (bureau) — symétrique de enrollOther. */
    public function unenrollOther(User $user, Session $session): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /** L'utilisateur agit-il pour lui-même ou pour un enfant dont il est le garant (§5.2) ? */
    private function actsForTarget(User $user, User $target): bool
    {
        return $user->id === $target->id || $target->guardian_id === $user->id;
    }

    /**
     * Inscrire un coach comme encadrant (§4.11.2). Tout coach + tout admin peut inscrire
     * n'importe quel coach (voie 3), ou soi-même (voie 2). L'admin pur peut inscrire des tiers
     * mais ne se propose pas l'auto-affectation (géré côté UI). $target null = pour soi.
     * Le rôle coach effectif de la cible est vérifié dans le service (§4.11.6 : un user dont le
     * rôle coach a été retiré ne peut plus être inscrit sur de NOUVELLES séances).
     */
    public function registerCoach(User $user, Session $session, ?User $target = null): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /**
     * Retirer un coach de l'encadrement (§4.11.2). Symétrique : tout coach + tout admin
     * (self-désinscription ou retrait d'un tiers).
     */
    public function unregisterCoach(User $user, Session $session, ?User $target = null): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /**
     * Modération d'un flag apéro (§4.14.4 voie 2) : retirer le flag d'un AUTRE membre. Réservé
     * coach/admin. Le self-déflag (voie 1) ne passe pas par ici. La pose du flag est un geste
     * personnel sans procuration (§4.14.1) : gardée dans le service, pas une autorisation de policy.
     */
    public function moderateApero(User $user, Session $session): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }
}
