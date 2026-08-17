<?php

namespace App\Livewire;

use App\Models\InformationPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Consultation des pages d'information (notes club) par les membres.
// Pas de garde de rôle : la liste est FILTRÉE par visibilité selon le regardeur
// (auth()->user()), jamais selon le sujet parent/enfant. Contenu court déplié en carte.
#[Layout('layouts.app')]
#[Title('Infos du club')]
class InformationPages extends Component
{
    public function render()
    {
        $pages = InformationPage::query()
            ->active()
            ->visibleTo(auth()->user())
            ->ordered()
            ->get();

        return view('livewire.information-pages', [
            'pages' => $pages,
        ]);
    }
}
