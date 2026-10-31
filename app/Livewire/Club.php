<?php

namespace App\Livewire;

use App\Models\NotificationOutbox;
use App\Policies\TrombiPolicy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Hub « Club » (#150) : dernier onglet de la barre du bas mobile, à la place de « Profil ».
// Index des écrans qui n'ont pas d'entrée dans la barre (profil, trombinoscope, infos, alertes).
// Le bureau n'y mène pas : sa barre latérale liste déjà tout.
#[Layout('layouts.app')]
#[Title('Club')]
class Club extends Component
{
    public function render()
    {
        $user = auth()->user();

        $roleMap = ['athlete' => 'Athlète', 'coach' => 'Coach', 'admin' => 'Admin'];
        $roleSub = implode(' · ', array_map(fn ($r) => $roleMap[$r] ?? ucfirst($r), $user->roles ?? []));
        if ($cat = $user->primaryCategory()) {
            $roleSub = trim($roleSub.' · '.$cat->label, ' ·');
        }

        return view('livewire.club', [
            'user' => $user,
            'roleSub' => $roleSub,
            'canTrombi' => app(TrombiPolicy::class)->consult($user),
            'unreadAlerts' => NotificationOutbox::unreadCountFor($user->id),
        ]);
    }
}
