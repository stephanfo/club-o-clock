<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Trombinoscope d'un enfant, réglé par son parent garant (#72, PRD §4.23.4) : photo et accords nom
// complet, photo, catégorie, anniversaire. Les gestes vivent dans TrombiSettings.
#[Layout('layouts.app')]
#[Title('Trombinoscope')]
class ChildTrombi extends Component
{
    public User $child;

    public function mount(User $child): void
    {
        abort_unless($child->guardian_id !== null && $child->guardian_id === auth()->id() && $child->anonymized_at === null, 403);
        $this->child = $child;
    }

    public function render()
    {
        return view('livewire.child-trombi');
    }
}
