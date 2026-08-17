<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Session;
use App\Models\User;

/**
 * Contexte catégoriel minimal pour les tests d'inscription (§4.5, garde catégorielle).
 *
 * Depuis l'ajout de la défense en profondeur (SessionPolicy::enroll + RegistrationService),
 * un athlète ne peut s'inscrire que sur une séance ciblant une de ses catégories actives. Ce trait
 * fournit une catégorie « ouverte » partagée à laquelle rattacher athlètes et séances pour que les
 * scénarios ne testant PAS la catégorie (capacité, quota, waitlist, notifs…) restent verts.
 *
 * Usage : `$cat = $this->openCategory();` puis attacher au besoin, ou `athlete()` / `targetCategory()`.
 */
trait EnrollableCategory
{
    private ?Category $openCategory = null;

    /** Catégorie active partagée du test (mémoïsée). */
    protected function openCategory(): Category
    {
        return $this->openCategory ??= Category::create([
            'label' => 'Adultes', 'age_min' => 20, 'age_max' => 99, 'sort_order' => 1,
        ]);
    }

    /** Athlète rattaché à la catégorie ouverte (principale), donc inscriptible sur ses séances. */
    protected function athlete(array $attributes = []): User
    {
        return $this->categorize(User::factory()->create($attributes));
    }

    /** Rattache un user existant à la catégorie ouverte (ex. athleteCoach qui bascule athlète). */
    protected function categorize(User $user): User
    {
        $user->categories()->syncWithoutDetaching([$this->openCategory()->id => ['is_primary' => true]]);
        $user->unsetRelation('categories');

        return $user;
    }

    /** Cible la séance sur la catégorie ouverte — la rend inscriptible par athlete(). */
    protected function targetCategory(Session $session): Session
    {
        $session->categories()->syncWithoutDetaching([$this->openCategory()->id]);

        return $session;
    }
}
