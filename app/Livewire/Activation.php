<?php

namespace App\Livewire;

use App\Actions\Fortify\PasswordValidationRules;
use App\Services\AuthMethodService;
use App\Services\PasswordService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Écran d'accueil après activation d'une invitation (PRD §4.1.3 : « l'adhérent choisit sa méthode
// d'auth à l'activation, la définition d'un mot de passe est optionnelle »).
//
// Il ne s'affiche qu'une fois, sur le drapeau de session posé par InvitationActivationController :
// ce n'est pas une surface permanente à maintenir en double — les mêmes gestes vivent dans le
// profil, onglet Connexion. Sans le drapeau, on renvoie au dashboard.
#[Layout('layouts.app')]
#[Title('Bienvenue')]
class Activation extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount()
    {
        if (! session('activation.pending')) {
            return $this->redirect(route('dashboard'), navigate: true);
        }
    }

    /** Pose le mot de passe puis entre dans l'app. Le compte est déjà connecté et vérifié. */
    public function definePassword(PasswordService $passwords)
    {
        $this->validate(['password' => $this->passwordRules()], attributes: ['password' => 'mot de passe']);

        $passwords->set(auth()->user(), $this->password);

        session()->forget('activation.pending');
        session()->flash('status', 'Mot de passe défini. Bienvenue !');

        return $this->redirect(route('dashboard'), navigate: true);
    }

    /** Continue sans mot de passe : le lien magique reste une méthode complète (§4.1.1). */
    public function skip()
    {
        session()->forget('activation.pending');
        session()->flash('status', 'Bienvenue !');

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(AuthMethodService $authMethods)
    {
        return view('livewire.activation', [
            'user' => auth()->user(),
            // On ne promet que ce qui est ouvert (§4.17) : un club qui a coupé le lien magique ne
            // doit pas lire « tu recevras un lien à chaque connexion ».
            'magicOn' => $authMethods->magicLinkEnabled(),
            'googleOn' => $authMethods->googleEnabled(),
        ]);
    }
}
