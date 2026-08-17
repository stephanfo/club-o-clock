<?php

namespace App\Policies;

use App\Models\Debrief;
use App\Models\User;

// Droits sur les débriefs (PRD §4.12.5). La création (participant + compétition commencée) est
// gardée dans DebriefService ; ici l'édition et l'archivage qui portent sur un Debrief existant.
class DebriefPolicy
{
    /** Édition : l'auteur (à tout moment) ou l'admin. */
    public function update(User $user, Debrief $debrief): bool
    {
        return $user->id === $debrief->author_id || $user->hasRole('admin');
    }

    /** Archivage / réactivation : admin uniquement (soft-delete). */
    public function archive(User $user, Debrief $debrief): bool
    {
        return $user->hasRole('admin');
    }
}
