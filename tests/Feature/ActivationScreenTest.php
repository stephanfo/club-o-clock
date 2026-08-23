<?php

namespace Tests\Feature;

use App\Livewire\Activation;
use App\Models\InvitationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

// Écran d'accueil après activation (PRD §4.1.3 : « l'adhérent choisit sa méthode d'auth à
// l'activation ; la définition d'un mot de passe est optionnelle »).
class ActivationScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Compte invité, sans credential — état réel d'un adhérent qui vient d'être créé. */
    private function invite(): User
    {
        return User::factory()->create(['password' => null, 'email' => 'invite@club.test']);
    }

    private function tokenFor(User $u): string
    {
        $token = 'jeton-'.$u->id;
        InvitationToken::create([
            'user_id' => $u->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        return $token;
    }

    public function test_activation_link_lands_on_the_welcome_screen(): void
    {
        $u = $this->invite();

        $this->get('/invitation/'.$this->tokenFor($u))->assertRedirect(route('activation'));
        $this->assertAuthenticatedAs($u);
        $this->get(route('activation'))->assertOk();
    }

    public function test_welcome_screen_is_out_of_reach_for_guests(): void
    {
        $this->get(route('activation'))->assertRedirect(route('login'));
    }

    public function test_welcome_screen_redirects_without_the_activation_flag(): void
    {
        // Ce n'est pas une surface permanente : hors du parcours d'activation, elle n'a rien à dire.
        // Les mêmes gestes vivent dans le profil, onglet Connexion.
        Livewire::actingAs($this->invite())->test(Activation::class)
            ->assertRedirect(route('dashboard'));
    }

    public function test_member_can_define_a_password_at_activation(): void
    {
        $u = $this->invite();
        session(['activation.pending' => true]);

        Livewire::actingAs($u)->test(Activation::class)
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('definePassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('motdepassesolide', $u->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_set', 'target_id' => $u->id]);
    }

    public function test_password_is_optional_at_activation(): void
    {
        $u = $this->invite();
        session(['activation.pending' => true]);

        Livewire::actingAs($u)->test(Activation::class)
            ->call('skip')
            ->assertRedirect(route('dashboard'));

        $this->assertNull($u->fresh()->password, 'Rester passwordless est un choix légitime (§4.1.1).');
    }

    public function test_weak_password_is_refused_at_activation(): void
    {
        $u = $this->invite();
        session(['activation.pending' => true]);

        Livewire::actingAs($u)->test(Activation::class)
            ->set('password', 'court')
            ->set('password_confirmation', 'court')
            ->call('definePassword')
            ->assertHasErrors('password');

        $this->assertNull($u->fresh()->password);
    }

    public function test_guardianship_link_survives_activation(): void
    {
        // §4.2.1 : l'autonomisation P1→P2 conserve le lien de tutelle.
        $garant = User::factory()->create();
        $pupille = User::factory()->create([
            'password' => null, 'email' => 'enfant@club.test',
            'is_minor' => true, 'guardian_id' => $garant->id,
        ]);

        $this->get('/invitation/'.$this->tokenFor($pupille))->assertRedirect(route('activation'));

        $this->assertSame($garant->id, $pupille->fresh()->guardian_id);
    }
}
