<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InvitationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Activation d'un compte mineur autonomisé (PRD §4.1.3, §4.2.1 ; page punted J7.7 → J8). Le pupille
// suit le lien reçu par email (type guardianship_invitation). Consommer le jeton = vérifier son
// email + le connecter ; le lien de tutelle (P2) reste en place. Passwordless : il pourra ensuite
// se reconnecter par magic link ou poser un mot de passe depuis son profil.
class GuardianshipActivationController extends Controller
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

        return redirect()->route('dashboard')->with('status', __('Ton compte est activé. Bienvenue !'));
    }
}
