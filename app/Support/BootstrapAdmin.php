<?php

namespace App\Support;

use App\Models\User;

// Bootstrap du premier admin (PRD §4.1.4, cadrage §7.3).
// L'email d'amorçage est en variable d'environnement (BOOTSTRAP_ADMIN_EMAIL). Le compte qui
// porte cet email obtient le rôle admin. Reproductible pour tout fork (one-instance-per-club).
class BootstrapAdmin
{
    /** Promeut l'utilisateur en admin si son email correspond à l'email de bootstrap. Idempotent. */
    public static function promoteIfMatches(User $user): bool
    {
        $bootstrapEmail = config('club.bootstrap_admin_email');

        if (! $bootstrapEmail || ! $user->email) {
            return false;
        }

        if (mb_strtolower($user->email) !== mb_strtolower($bootstrapEmail)) {
            return false;
        }

        if (! $user->grantRole('admin')) {
            return false; // déjà admin, rien à faire
        }

        $user->save();

        return true;
    }
}
