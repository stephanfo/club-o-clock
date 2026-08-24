<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // Un compte passwordless (créé par invitation ou activation de tutelle) n'a pas d'ancien mot
        // de passe à prouver : exiger `current_password` en dur rendrait la route inutilisable pour
        // lui. Même règle conditionnelle que Profil::savePassword() — la route Fortify reste
        // enregistrée et joignable, deux jeux de règles divergents seraient un piège.
        Validator::make($input, [
            ...($user->password !== null ? ['current_password' => ['required', 'string', 'current_password:web']] : []),
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
