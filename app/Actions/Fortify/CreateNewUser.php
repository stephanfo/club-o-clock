<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\BootstrapAdmin;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

// La registration self-service est désactivée (PRD §4.1.3 : comptes créés par admin/invitation).
// Cette action reste le point d'entrée unique de création de compte, réutilisée par les flows
// admin/invitation. Le tout premier compte portant l'email de bootstrap reçoit le rôle admin (§7.3).
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'dob' => ['required', 'date'],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'dob' => $input['dob'],
            'password' => $input['password'], // hashé par le cast 'password' => 'hashed'
            'roles' => ['athlete'],
        ]);

        // Bootstrap admin (§7.3) : promotion idempotente du compte d'amorçage.
        BootstrapAdmin::promoteIfMatches($user);

        return $user;
    }
}
