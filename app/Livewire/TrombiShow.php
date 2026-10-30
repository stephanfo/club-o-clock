<?php

namespace App\Livewire;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Support\Trombi\TrombiCard;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Fiche d'un adhérent au trombinoscope (#72, PRD §4.23.6) : la carte en grand, plus les
// coordonnées partagées en liens d'appel et d'écriture. Même TrombiPolicy que la grille.
#[Layout('layouts.app')]
#[Title('Trombinoscope')]
class TrombiShow extends Component
{
    public User $user;

    public function mount(User $user, TrombiPolicy $policy): void
    {
        $viewer = auth()->user();
        abort_unless($policy->consult($viewer), 403);
        // 404 : quelqu'un qui ne figure pas ne doit pas se distinguer d'un compte inexistant.
        abort_unless($policy->figures($user) || $user->id === $viewer->id, 404);

        $this->user = $user;
    }

    public function render(TrombiPolicy $policy)
    {
        $this->user->loadMissing(TrombiCard::EAGER);

        return view('livewire.trombi-show', [
            'card' => TrombiCard::make(auth()->user(), $this->user, $policy),
        ]);
    }
}
