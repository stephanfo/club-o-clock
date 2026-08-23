<?php

namespace Tests\Feature;

use App\Livewire\Profil;
use App\Models\User;
use App\Services\InvitationService;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

// Politique de mot de passe (§4.1.2) — une seule règle, un seul énoncé.
//
// Le trou que ces tests ferment : la longueur minimale vivait en trois exemplaires qui avaient
// divergé. `Password::min(10)` dans FortifyServiceProvider, `Password::min(8)` dans
// `club:create-admin`, et un écran de réinitialisation promettant « Au moins 8 caractères » avant
// de refuser une saisie à 8. L'adhérent découvrait la vraie règle par le message d'erreur.
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_screen_announces_the_rule_it_will_enforce(): void
    {
        $membre = User::factory()->create(['email' => 'membre@club.test']);
        $token = Password::broker()->createToken($membre);

        $reponse = $this->get(route('password.reset', ['token' => $token, 'email' => $membre->email]))->assertOk();

        // La longueur annoncée est CELLE de la politique, quelle qu'elle devienne.
        $reponse->assertSee(PasswordPolicy::MIN.' caractères');
        $reponse->assertDontSee('8 caractères');
    }

    public function test_the_activation_screen_announces_the_rule(): void
    {
        // L'écran d'activation n'annonçait aucune longueur : l'adhérent découvrait la règle en se
        // faisant refuser sa saisie, au moment précis où il entre dans l'application pour la
        // première fois (§4.1.3).
        $membre = User::factory()->create(['email' => 'invite@club.test', 'password' => null]);
        $token = app(InvitationService::class)->mint($membre);

        // Le GET consomme le jeton, connecte et redirige vers l'écran d'accueil : c'est LUI qui
        // propose de poser un mot de passe.
        $this->get("/invitation/{$token}")->assertRedirect(route('activation'));

        $this->get(route('activation'))
            ->assertOk()
            ->assertSee(PasswordPolicy::MIN.' caractères');
    }

    public function test_the_profile_screen_announces_the_rule(): void
    {
        $membre = User::factory()->create(['email' => 'membre@club.test', 'password' => null]);

        Livewire::actingAs($membre)->test(Profil::class)
            ->set('tab', 'connexion')
            ->assertSee(PasswordPolicy::MIN.' caractères');
    }

    public function test_every_password_surface_announces_the_same_length(): void
    {
        // Contrôle apparié aux trois écrans : aucun ne doit annoncer une AUTRE longueur. C'est la
        // divergence corrigée — 10 appliqué, 8 promis.
        foreach ([
            resource_path('views/auth/reset-password.blade.php'),
            resource_path('views/livewire/activation/_body.blade.php'),
            resource_path('views/livewire/profil/_connexion.blade.php'),
        ] as $vue) {
            $this->assertDoesNotMatchRegularExpression('/\d+ caractères/', file_get_contents($vue),
                basename($vue).' : la longueur est écrite en dur au lieu de sortir de PasswordPolicy.');
        }
    }

    public function test_a_password_one_char_short_is_refused_on_reset(): void
    {
        $membre = User::factory()->create(['email' => 'membre@club.test']);
        $token = Password::broker()->createToken($membre);
        $court = str_repeat('a', PasswordPolicy::MIN - 1);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $membre->email,
            'password' => $court, 'password_confirmation' => $court,
        ])->assertSessionHasErrors('password');
    }

    public function test_a_password_at_the_announced_length_is_accepted(): void
    {
        // Contrôle positif apparié : la borne annoncée est bien la borne appliquée, pas une de plus.
        $membre = User::factory()->create(['email' => 'membre@club.test']);
        $token = Password::broker()->createToken($membre);
        $pile = str_repeat('a', PasswordPolicy::MIN);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $membre->email,
            'password' => $pile, 'password_confirmation' => $pile,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_console_command_enforces_the_same_rule_as_every_screen(): void
    {
        // `club:create-admin` est le seul point d'entrée d'une base vide (§ amorçage) : c'était donc
        // le seul compte du club autorisé à porter un mot de passe plus faible que les autres.
        $this->artisan('club:create-admin', [
            'email' => 'admin@club.test',
            '--first-name' => 'Jean', '--last-name' => 'Dupont',
            '--password' => str_repeat('a', PasswordPolicy::MIN - 1),
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@club.test']);
    }

    public function test_the_console_command_accepts_a_compliant_password(): void
    {
        // Contrôle positif apparié : le durcissement ne ferme pas l'amorçage.
        $this->artisan('club:create-admin', [
            'email' => 'admin@club.test',
            '--first-name' => 'Jean', '--last-name' => 'Dupont',
            '--password' => str_repeat('a', PasswordPolicy::MIN),
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@club.test']);
    }
}
