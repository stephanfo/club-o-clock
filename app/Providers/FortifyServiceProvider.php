<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Authentification par mot de passe : même garde `is_active` que le magic link et l'OAuth
        // (§4.3 — un compte désactivé ou en attente de suppression ne peut PAS ouvrir un NOUVEAU
        // login). Lookup email insensible à la casse (emails stockés en minuscules).
        Fortify::authenticateUsing(function (Request $request) {
            $email = mb_strtolower((string) $request->input(Fortify::username()));
            $user = User::findByEmail($email);

            if ($user
                && $user->is_active
                && $user->password !== null
                && Hash::check((string) $request->input('password'), $user->password)) {
                return $user;
            }

            return null;
        });

        // Vues d'auth = Blade maison (Fortify nu, cadrage §14.1). Pas de 2FA/passkeys (V1).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        // Throttle login (PRD §4.1 : protection brute-force). 5 tentatives/min par (email + IP).
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
