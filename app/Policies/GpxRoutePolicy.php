<?php

namespace App\Policies;

use App\Models\GpxRoute;
use App\Models\User;

// Droits sur les parcours GPX (PRD §4.20). Lecture ouverte à tous les membres connectés (la
// bibliothèque est un espace de consultation), écriture réservée à l'encadrement.
class GpxRoutePolicy
{
    /** Consultation de la bibliothèque : tout membre connecté. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GpxRoute $route): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    public function update(User $user, GpxRoute $route): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /** Archivage / restauration : coach ou admin (geste réversible). */
    public function archive(User $user, GpxRoute $route): bool
    {
        return $user->hasRole('coach') || $user->hasRole('admin');
    }

    /**
     * Suppression définitive : admin seul. Même asymétrie que SessionPolicy — un coach ne doit pas
     * pouvoir détruire un parcours que N séances référencent. La garde « parcours utilisé » elle-même
     * vit dans GpxRouteService::delete().
     */
    public function delete(User $user, GpxRoute $route): bool
    {
        return $user->hasRole('admin');
    }
}
