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
            $emis = MagicLink::issue($email);
            Notification::route('mail', $email)->notify(new MagicLinkNotification($emis['url'], $emis['code']));
        }

        // On atterrit sur l'écran de vérification, identique que le compte existe ou non :
        // l'anti-énumération tient à ce que rien ne diffère ici (même redirection, même contenu).
        // Seule la saisie d'un code peut échouer, avec le même message générique pour toutes les
        // causes.
        $request->session()->put('magic-link.email', $email);

        return redirect()->route('magic-link.sent');
    }

    /** « Un email t'attend » : le lien est parti, et le code se saisit ici. */
    public function sent(Request $request)
    {
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login');
        }

        return view('auth.magic-link-sent', [
            'email' => (string) $request->session()->get('magic-link.email', ''),
        ]);
    }

    /**
     * Saisie d'un code seul, sans être passé par la demande dans ce contexte de navigation. C'est
     * LE chemin de la PWA iOS : le lien a été demandé depuis Safari, l'utilisateur ouvre ensuite
     * l'application installée, où la session est vierge.
     */
    public function codeForm(Request $request)
    {
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login');
        }

        return view('auth.magic-link-code', [
            'email' => (string) $request->session()->get('magic-link.email', ''),
        ]);
    }

    /** Vérifie le code à usage unique et connecte. */
    public function verifyCode(Request $request)
    {
        // Avant toute consommation, comme consume() : un refus ne doit pas brûler un jeton que le
        // club pourrait vouloir honorer en réactivant le moyen.
        if (! $this->authMethods->magicLinkEnabled()) {
            return redirect()->route('login')->withErrors([
                'email' => __('La connexion par lien est désactivée.'),
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        // DEUX limiteurs, complémentaires. Celui par (email+IP) borne l'acharnement sur une cible ;
        // celui par IP seule borne le balayage de nombreux emails, que le compteur par jeton ne voit
        // pas — un attaquant qui essaie un code sur mille adresses n'épuise le compteur d'aucune.
        $ip = (string) $request->ip();
        $parCible = 'magic-code|'.mb_strtolower($validated['email']).'|'.$ip;
        $parIp = 'magic-code|'.$ip;

        if (RateLimiter::tooManyAttempts($parCible, 5) || RateLimiter::tooManyAttempts($parIp, 10)) {
            return back()->withErrors(['code' => __('Trop de tentatives. Réessaie dans quelques minutes.')]);
        }

        RateLimiter::hit($parCible, 60);
        RateLimiter::hit($parIp, 600);

        $email = MagicLink::consumeCode($validated['email'], $validated['code']);
        $user = $email !== null ? User::findByEmail($email) : null;

        // Message unique pour toutes les causes (code faux, expiré, brûlé, email inconnu, compte
        // fermé) : distinguer renseignerait un attaquant sur l'existence du compte.
        if (! $user || ! $user->is_active) {
            $request->session()->put('magic-link.email', $validated['email']);

            return back()->withErrors(['code' => __('Ce code est invalide ou expiré.')]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
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
