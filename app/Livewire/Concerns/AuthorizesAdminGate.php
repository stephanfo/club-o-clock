<?php

namespace App\Livewire\Concerns;

/**
 * Rend structurelle la garde d'autorisation des composants admin à gate unique.
 *
 * Livewire appelle `boot<Trait>()` à CHAQUE requête du composant — au mount comme sur chaque
 * action suivante (contrairement à `mount()`, joué une seule fois). Un composant qui `use` ce
 * trait et surcharge `adminGate()` voit donc sa gate vérifiée avant toute méthode, sans avoir à
 * répéter `$this->authorize(...)` dans chaque action (source d'oublis silencieux — cf. rattrapage
 * 667dc9c). Réservé aux composants dont l'accès dépend d'une seule gate globale ; les composants
 * autorisant un modèle précis (update/archive sur $tpl…) gardent leur authorize par action.
 */
trait AuthorizesAdminGate
{
    /** Gate à vérifier à chaque requête. Surchargée par le composant ; null = pas de garde. */
    protected function adminGate(): ?string
    {
        return null;
    }

    public function bootAuthorizesAdminGate(): void
    {
        $gate = $this->adminGate();

        if ($gate !== null) {
            $this->authorize($gate);
        }
    }
}
