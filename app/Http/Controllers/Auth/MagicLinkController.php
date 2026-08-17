<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\AuthMethodService;
use App\Support\MagicLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// Connexion par magic link (PRD §4.1.1). Passwordless : tout email vérifié peut recevoir un lien.
//
// Le club peut fermer ce moyen (§4.17) : les TROIS méthodes sont gardées, la demande comme la
// consommation. Sans la garde sur consume(), les liens déjà envoyés resteraient valides jusqu'à
// 15 min après la coupure.
class MagicLinkController extends Controller
{
    public function __construct(private AuthMethodService $authMethods) {}

    /** Formulaire de demande de lien. */
    public function request()
    {
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login');
        }

        return view('auth.magic-link');
    }

    /** Envoi du lien. Réponse neutre (pas d'énumération de comptes). */
    public function send(Request $request)
    {
        // Garde avant validation et throttle : rien à protéger ici, le moyen est publiquement
        // coupé — pas de message neutre anti-énumération, il n'y a pas de compte à deviner.
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login')->withErrors([
                'email' => __('La connexion par lien est désactivée.'),
            ]);
        }

        $validated = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower($validated['email']);

        // Throttle : 5 demandes/min par (email + IP).
        $key = 'magic-link|'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('status', __('Trop de demandes. Réessaie dans un instant.'));
        }
        RateLimiter::hit($key, 60);

        // On n'envoie un lien que si un compte existe ET a un email vérifié — mais la réponse
        // reste identique dans tous les cas (anti-énumération).
        $user = User::findByEmail($email);
        if ($user && $user->hasVerifiedEmail()) {
            $url = MagicLink::createUrlFor($email);
            Notification::route('mail', $email)->notify(new MagicLinkNotification($url));
        }

        return back()->with('status', __('Si un compte existe pour cet email, un lien de connexion vient d\'être envoyé.'));
    }

    /** Consommation du lien : connecte l'utilisateur si le token est valide. */
    public function consume(Request $request, string $token)
    {
        // AVANT MagicLink::consume() : la consommation est atomique et irréversible, un refus ne
        // doit pas brûler un token que le club pourrait vouloir honorer en réactivant le moyen.
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login')->withErrors([
                'email' => __('La connexion par lien a été désactivée. Utilise ton mot de passe ou contacte le bureau.'),
            ]);
        }

        $email = MagicLink::consume($token);

        if (! $email) {
            return redirect()->route('login')->withErrors(['email' => __('Ce lien est invalide ou expiré.')]);
        }

        $user = User::findByEmail($email);

        if (! $user || ! $user->is_active) {
            return redirect()->route('login')->withErrors(['email' => __('Ce compte n\'est pas accessible.')]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
