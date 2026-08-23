<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InvitationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Activation d'un compte via son lien d'invitation (PRD §4.1.3, §4.2.1). Deux origines, un seul
// chemin : l'adhérent créé par le bureau et le mineur autonomisé reçoivent le même type de jeton et
// activent de la même façon — activer un compte est le même geste, quelle qu'en soit la raison.
//
// Consommer le jeton = vérifier l'email + connecter. Le lien de tutelle (P2) reste en place.
// Passwordless : l'écran d'accueil qui suit propose de poser un mot de passe, sans l'imposer (§4.1.3
// « la définition d'un mot de passe est optionnelle »).
//
// Le jeton est consommé ICI, au GET, et non dans l'écran Livewire qui suit : un composant monté sur
// le jeton le porterait en clair dans le DOM et dans chacun de ses payloads (historique, cache,
// capture d'écran). Le seul inconvénient — un onglet fermé trop tôt brûle le lien — se paie à un
// moment où l'adhérent est déjà connecté et son email vérifié : il repasse par le lien magique.
class InvitationActivationController extends Controller
{
    public function activate(Request $request, string $token)
    {
        $invitation = InvitationToken::where('token_hash', hash('sha256', $token))->first();

        if (! $invitation || ! $invitation->isUsable()) {
            return redirect()->route('login')->withErrors([
                'email' => __('Ce lien d\'activation est invalide ou expiré.'),
            ]);
        }

        $user = $invitation->user;

        if (! $user || ! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => __('Ce compte n\'est pas accessible.'),
            ]);
        }

        DB::transaction(function () use ($invitation, $user) {
            $invitation->update(['consumed_at' => Carbon::now()]);

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            }
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Drapeau posé APRÈS regenerate() (qui vide la session) : il ouvre l'écran d'accueil une
        // fois et une seule. Sans lui, /bienvenue renverrait au dashboard.
        $request->session()->put('activation.pending', true);

        return redirect()->route('activation');
    }
}
