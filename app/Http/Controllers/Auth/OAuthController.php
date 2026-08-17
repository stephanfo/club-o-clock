<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\AuthMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

// Connexion Google via Socialite (client OAuth seul — cadrage §14.1). Stocke email + name + sub.
// Pas de self-registration : on lie/connecte un compte EXISTANT (créé par admin/invitation, §4.1.3).
class OAuthController extends Controller
{
    private const SUPPORTED = ['google'];

    public function __construct(private AuthMethodService $authMethods) {}

    public function redirect(string $provider)
    {
        $this->abortIfUnavailable($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider)
    {
        // Gardé comme redirect() : le callback est une URL publique, atteignable sans passer par
        // l'entrée. Sans cette garde, la coupure serait contournable par un appel direct.
        $this->abortIfUnavailable($provider);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route('login')->withErrors(['email' => __('La connexion Google a échoué.')]);
        }

        $providerUid = $oauthUser->getId();
        $email = $oauthUser->getEmail() ? mb_strtolower($oauthUser->getEmail()) : null;

        // 1) Identité déjà liée → connexion directe.
        $identity = AuthIdentity::where('provider', $provider)
            ->where('provider_uid', $providerUid)
            ->first();

        if ($identity) {
            return $this->loginUser($request, $identity->user);
        }

        // 2) Pas d'identité liée : on tente le linking sur un email vérifié existant (§4.1).
        if (! $email) {
            return redirect()->route('login')->withErrors(['email' => __('Aucun email fourni par Google.')]);
        }

        // §4.1.2 : le linking exige que l'email soit vérifié CÔTÉ GOOGLE (claim `email_verified` du
        // userinfo OIDC ; `verified_email` sur l'ancien endpoint). Sinon usurpation possible d'un
        // compte club par un compte Google non confirmé portant le même email.
        $claimVerified = (bool) ($oauthUser->user['email_verified'] ?? $oauthUser->user['verified_email'] ?? false);
        if (! $claimVerified) {
            return redirect()->route('login')->withErrors([
                'email' => __('Ton email Google n\'est pas vérifié : vérifie-le côté Google avant de lier ce compte.'),
            ]);
        }

        $user = User::findByEmail($email);

        // Linking autorisé seulement sur un compte existant à email VÉRIFIÉ (sinon usurpation possible).
        if (! $user || ! $user->hasVerifiedEmail()) {
            return redirect()->route('login')->withErrors([
                'email' => __('Aucun compte vérifié ne correspond à cet email Google. Demande une invitation à l\'administrateur.'),
            ]);
        }

        AuthIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_uid' => $providerUid,
            'email_at_link' => $email,
            'linked_at' => Carbon::now(),
        ]);

        return $this->loginUser($request, $user);
    }

    /**
     * Provider non supporté, coupé par le club, ou client OAuth non configuré (§4.17) → 404, comme
     * une route inexistante. Un 403 ou un message dédié renseignerait sur la configuration de
     * l'instance ; pour l'appelant, ce chemin d'auth n'existe simplement pas.
     */
    private function abortIfUnavailable(string $provider): void
    {
        abort_unless(in_array($provider, self::SUPPORTED, true), 404);
        abort_unless($this->authMethods->googleEnabled(), 404);
    }

    private function loginUser(Request $request, User $user)
    {
        if (! $user->is_active) {
            return redirect()->route('login')->withErrors(['email' => __('Ce compte n\'est pas accessible.')]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
