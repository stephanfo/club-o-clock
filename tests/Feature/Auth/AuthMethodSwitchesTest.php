<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\ClubSettingsForm;
use App\Livewire\Profil;
use App\Models\AuthIdentity;
use App\Models\ClubSettings;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Support\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Tests\TestCase;

// Interrupteurs des moyens de connexion (§4.17, extension de §4.1.1). Le mot de passe n'est jamais
// coupé ; couper un autre moyen est refusé si des comptes n'ont plus aucun accès (invariant §4.1.2).
class AuthMethodSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function setSettings(array $attributes): void
    {
        ClubSettings::current()->update($attributes);
        ClubSettings::flushCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
    }

    private function mockGoogleUser(string $email, bool $claimVerified = true, string $uid = 'g-123'): void
    {
        $su = (new SocialiteUser)->map(['id' => $uid, 'email' => $email, 'name' => 'Demo User']);
        $su->user = ['email_verified' => $claimVerified];

        Socialite::shouldReceive('driver->user')->andReturn($su);
    }

    // ── Défaut : rien n'est coupé ──

    public function test_login_screen_offers_all_methods_by_default(): void
    {
        $response = $this->get(route('login'));

        $response->assertSee('Continuer avec Google');
        $response->assertSee('Envoyer le lien');
        $response->assertSee('Se connecter');
    }

    // ── Lien magique coupé ──

    public function test_magic_link_request_form_redirects_when_disabled(): void
    {
        $this->setSettings(['auth_magic_link_enabled' => false]);

        $this->get(route('magic-link.request'))->assertRedirect(route('login'));
    }

    public function test_magic_link_send_is_refused_and_issues_no_token(): void
    {
        $this->setSettings(['auth_magic_link_enabled' => false]);
        User::factory()->create(['email' => 'marie@demo.club']);

        $response = $this->post(route('magic-link.send'), ['email' => 'marie@demo.club']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_link_already_in_flight_is_refused_without_burning_the_token(): void
    {
        // Lien émis AVANT la coupure : la garde doit précéder MagicLink::consume(), sinon le token
        // serait consommé (donc perdu) alors même que la connexion est refusée.
        User::factory()->create(['email' => 'marie@demo.club']);
        $url = MagicLink::createUrlFor('marie@demo.club');
        $this->setSettings(['auth_magic_link_enabled' => false]);

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull(MagicLinkToken::firstOrFail()->consumed_at);
    }

    public function test_link_still_works_after_the_club_reopens_the_method(): void
    {
        $user = User::factory()->create(['email' => 'marie@demo.club']);
        $url = MagicLink::createUrlFor('marie@demo.club');

        $this->setSettings(['auth_magic_link_enabled' => false]);
        $this->get($url)->assertRedirect(route('login'));

        $this->setSettings(['auth_magic_link_enabled' => true]);
        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_screen_shows_password_form_alone_when_magic_link_is_off(): void
    {
        $this->setSettings(['auth_magic_link_enabled' => false]);

        $response = $this->get(route('login'));

        $response->assertDontSee('Envoyer le lien');
        // Le formulaire mot de passe doit rester VISIBLE : hors du wrapper `.auth-method`, il ne
        // porte plus `pane-pwd` (que le CSS masque en l'absence du radio qui le révèle).
        $response->assertSee('Se connecter');
        $response->assertDontSee('pane-pwd');
        $response->assertSee('La connexion par lien a été désactivée par le bureau.', escape: false);
    }

    // ── Google coupé ──

    public function test_google_redirect_and_callback_are_both_closed(): void
    {
        $this->setSettings(['auth_google_enabled' => false]);

        $this->get('/auth/google/redirect')->assertNotFound();
        // Le callback est une URL publique : sans garde ici, la coupure serait contournable.
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_google_callback_cannot_link_an_identity_while_disabled(): void
    {
        $this->setSettings(['auth_google_enabled' => false]);
        User::factory()->create(['email' => 'marie@demo.club']);
        $this->mockGoogleUser('marie@demo.club');

        $this->get('/auth/google/callback')->assertNotFound();

        $this->assertSame(0, AuthIdentity::count());
        $this->assertGuest();
    }

    public function test_google_is_unavailable_when_no_oauth_client_is_configured(): void
    {
        // Interrupteur ouvert mais client absent : le bouton menait à une erreur Google.
        config(['services.google.client_id' => '']);

        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get(route('login'))->assertDontSee('Continuer avec Google');
    }

    public function test_login_screen_hides_google_button_when_disabled(): void
    {
        $this->setSettings(['auth_google_enabled' => false]);

        $response = $this->get(route('login'));

        $response->assertDontSee('Continuer avec Google');
        // Le séparateur « ou » n'a plus rien à séparer.
        $response->assertDontSee('auth-or');
        $response->assertSee('Envoyer le lien');
    }

    // ── Garde de non-verrouillage (§4.1.2) ──

    public function test_disabling_magic_link_is_refused_when_an_account_depends_on_it(): void
    {
        // Compte passwordless sans identité liée : typique d'une activation de tutelle (§4.2.1).
        User::factory()->create(['email' => 'orphan@demo.club', 'password' => null, 'is_active' => true]);

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'magic_link')
            ->assertSet('auth_magic_link_enabled', true)
            ->assertSee('plus aucun moyen de se connecter');

        ClubSettings::flushCache();
        $this->assertTrue(ClubSettings::current()->auth_magic_link_enabled);
    }

    public function test_disabling_magic_link_passes_once_the_account_has_a_password(): void
    {
        User::factory()->create(['email' => 'safe@demo.club', 'password' => 'password', 'is_active' => true]);

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'magic_link')
            ->assertSet('auth_magic_link_enabled', false);

        ClubSettings::flushCache();
        $this->assertFalse(ClubSettings::current()->auth_magic_link_enabled);
    }

    public function test_inactive_and_anonymised_accounts_never_block_a_switch(): void
    {
        User::factory()->create(['email' => 'gone@demo.club', 'password' => null, 'is_active' => false]);
        User::factory()->create([
            'email' => 'ghost@demo.club', 'password' => null, 'is_active' => true,
            'anonymized_at' => Carbon::now(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'magic_link')
            ->assertSet('auth_magic_link_enabled', false);
    }

    public function test_disabling_google_is_refused_for_an_account_that_only_has_google(): void
    {
        // Pas d'email, pas de mot de passe : seule une identité Google donne accès à ce compte.
        $user = User::factory()->create(['email' => null, 'password' => null, 'is_active' => true]);
        AuthIdentity::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_uid' => 'g-solo',
            'email_at_link' => 'solo@demo.club', 'linked_at' => Carbon::now(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'google')
            ->assertSet('auth_google_enabled', true);

        ClubSettings::flushCache();
        $this->assertTrue(ClubSettings::current()->auth_google_enabled);
    }

    public function test_admin_toggle_records_audit_and_ignores_unknown_method(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'carrier_pigeon')
            ->assertSet('auth_magic_link_enabled', true)
            ->assertSet('auth_google_enabled', true);

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->call('toggleAuthMethod', 'google')
            ->assertSet('auth_google_enabled', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'club_settings_updated']);
    }

    // ── Garde-fou du profil, désormais sensible aux interrupteurs ──

    public function test_revoking_google_is_blocked_when_magic_link_is_closed(): void
    {
        // Sans mot de passe : l'email ne vaut un accès QUE si le lien magique est ouvert. Avant que
        // le garde-fou consulte les interrupteurs, cette révocation passait et verrouillait dehors.
        $user = User::factory()->create(['email' => 'marie@demo.club', 'password' => null, 'is_active' => true]);
        $identity = AuthIdentity::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_uid' => 'g-777',
            'email_at_link' => 'marie@demo.club', 'linked_at' => Carbon::now(),
        ]);
        $this->setSettings(['auth_magic_link_enabled' => false]);

        Livewire::actingAs($user)
            ->test(Profil::class, ['tab' => 'connexion'])
            ->call('revokeMethod', $identity->id)
            ->assertSee('seule méthode de connexion');

        $this->assertSame(1, AuthIdentity::count());
    }

    public function test_revoking_google_is_allowed_when_magic_link_stays_open(): void
    {
        $user = User::factory()->create(['email' => 'marie@demo.club', 'password' => null, 'is_active' => true]);
        $identity = AuthIdentity::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_uid' => 'g-888',
            'email_at_link' => 'marie@demo.club', 'linked_at' => Carbon::now(),
        ]);

        Livewire::actingAs($user)
            ->test(Profil::class, ['tab' => 'connexion'])
            ->call('revokeMethod', $identity->id);

        $this->assertSame(0, AuthIdentity::count());
    }

    public function test_profile_marks_a_linked_method_closed_by_the_club(): void
    {
        $user = User::factory()->create(['email' => 'marie@demo.club', 'password' => 'password']);
        $this->setSettings(['auth_magic_link_enabled' => false]);

        Livewire::actingAs($user)
            ->test(Profil::class, ['tab' => 'connexion'])
            ->assertSee('Désactivé par le club');
    }
}
