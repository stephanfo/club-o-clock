<?php

namespace App\Services;

use App\Models\User;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Facades\Hash;

// Pose, changement et retrait du mot de passe (PRD §4.1.1, §4.1.5). Le mot de passe est une méthode
// d'auth parmi d'autres : un compte créé par invitation ou activation de tutelle n'en a pas, et n'est
// pas tenu d'en avoir un — d'où la distinction entre POSER (aucun mot de passe courant à vérifier) et
// CHANGER (il faut prouver qu'on détient l'ancien).
//
// Le service ne connaît ni le contexte de session ni les autres moyens du compte : la vérification du
// mot de passe courant, la garde « ne pas se verrouiller dehors » (AuthMethodService) et la politique
// de déconnexion des autres appareils restent chez l'appelant, qui seul sait d'où vient la demande.
//
// Un admin n'appelle JAMAIS ce service pour un tiers : il ne peut que déclencher l'envoi d'un lien de
// réinitialisation (MemberShow::sendPasswordReset). Détenir le facteur d'authentification d'un autre
// compte rendrait l'usurpation possible et indétectable — c'est une propriété de sécurité, pas un
// détail d'implémentation.
class PasswordService
{
    /**
     * Pose ou remplace le mot de passe. `$action` distingue les deux au journal : poser un premier
     * mot de passe ajoute un moyen d'accès, le changer en remplace un — deux faits différents pour
     * qui relit un audit.
     */
    public function set(User $user, string $plain): void
    {
        $action = $user->password === null ? 'password_set' : 'password_changed';

        $user->forceFill(['password' => Hash::make($plain)])->save();

        AuditLogger::record($action, $user, [
            'target_type' => User::class,
            'target_id' => $user->id,
        ]);
    }

    /**
     * Retire le mot de passe (le compte redevient passwordless). L'appelant a vérifié qu'il reste
     * un autre moyen d'entrer — ici on ne fait que constater le retrait.
     */
    public function remove(User $user): void
    {
        $user->forceFill(['password' => null])->save();

        AuditLogger::record('password_removed', $user, [
            'target_type' => User::class,
            'target_id' => $user->id,
        ]);
    }
}
