<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'dob' => fake()->dateTimeBetween('-50 years', '-20 years')->format('Y-m-d'),
            'roles' => ['athlete'],
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['roles' => ['admin']]);
    }

    public function coach(): static
    {
        return $this->state(fn (array $attributes) => ['roles' => ['coach']]);
    }

    /** Athlète ET coach (rôles cumulés, §2) : seul profil pouvant basculer coach ↔ athlète. */
    public function athleteCoach(): static
    {
        return $this->state(fn (array $attributes) => ['roles' => ['athlete', 'coach']]);
    }

    /** Mineur P1 : sans email ni credential (PRD §4.2). */
    public function minorP1(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'is_minor' => true,
            'dob' => fake()->dateTimeBetween('-12 years', '-8 years')->format('Y-m-d'),
            'roles' => ['athlete'],
        ]);
    }
}
