<?php

namespace App\Policies;

use App\Models\SessionTemplate;
use App\Models\User;

// Contrôle d'accès SessionTemplate (PRD §4.8). ADMIN UNIQUEMENT pour création / édition /
// archivage / (re)génération : un template produit N Session à l'enregistrement (impact saison),
// c'est un geste de pilotage. Les coachs créent librement des Session standalone (SessionPolicy).
class SessionTemplatePolicy
{
    /** Liste des modèles : admin seul (back-office §4.8). */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SessionTemplate $template): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SessionTemplate $template): bool
    {
        return $user->isAdmin();
    }

    /** Archivage = soft-delete (status archived, §4.8). */
    public function archive(User $user, SessionTemplate $template): bool
    {
        return $user->isAdmin();
    }

    /** (Re)génération — création/relance d'une plage (§4.8 Réutilisation). */
    public function generate(User $user, SessionTemplate $template): bool
    {
        return $user->isAdmin();
    }
}
