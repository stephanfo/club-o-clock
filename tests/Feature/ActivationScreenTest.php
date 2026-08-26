<?php

namespace Tests\Feature;

use App\Livewire\Activation;
use App\Models\ClubSettings;
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

    /** Écrit un réglage du singleton. flushCache() car TestCase ne le vide qu'au setUp(). */
    private function setSettings(array $attributes): void
    {
        ClubSettings::current()->update($attributes);
        ClubSettings::flushCache();
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

    // ── Annonce des moyens de reconnexion (§4.1.3, §4.17) ──
    // Le compte est déjà connecté ici : l'écran ANNONCE comment revenir, il n'authentifie pas.
    // D'où l'absence volontaire de bouton `oauth.redirect`, vérifiée ci-dessous.

    public function test_welcome_screen_announces_google_when_it_is_open(): void
    {
        config(['services.google.client_id' => 'client-test.apps.googleusercontent.com']);
        $this->setSettings(['auth_google_enabled' => true]);
        $u = $this->invite();

        $this->get('/invitation/'.$this->tokenFor($u));

        $this->get(route('activation'))
            ->assertOk()
            ->assertSee('Se connecter avec Google')
            // La condition sur l'adresse reste visible : un Gmail personnel ≠ email club échoue
            // au login, et l'échec silencieux serait plus frustrant que la mise en garde.
            ->assertSee($u->email);
    }

    public function test_welcome_screen_hides_google_when_the_club_closed_it(): void
    {
        config(['services.google.client_id' => 'client-test.apps.googleusercontent.com']);
        $this->setSettings(['auth_google_enabled' => false]);
        $u = $this->invite();

        $this->get('/invitation/'.$this->tokenFor($u));

        $this->get(route('activation'))
            ->assertOk()
            ->assertDontSee('Se connecter avec Google')
            // Contrôle positif : l'écran a bien rendu, l'absence n'est pas celle d'une page vide.
            ->assertSee('Définir un mot de passe');
    }

    public function test_welcome_screen_never_offers_the_oauth_button(): void
    {
        // Le compte est DÉJÀ connecté : un aller-retour OAuth le ramènerait où il est, et son
        // `intended(dashboard)` court-circuiterait l'occasion de poser un mot de passe.
        config(['services.google.client_id' => 'client-test.apps.googleusercontent.com']);
        $this->setSettings(['auth_google_enabled' => true]);
        $u = $this->invite();

        $this->get('/invitation/'.$this->tokenFor($u));

        $this->get(route('activation'))
            ->assertOk()
            ->assertDontSee(route('oauth.redirect', 'google'), false)
            ->assertSee('Se connecter avec Google');
    }
}
