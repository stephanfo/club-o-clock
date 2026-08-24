<?php

namespace Tests\Feature\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Support\BootstrapAdmin;
use App\Support\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'a@club.test', 'password' => 'password']);

        $response = $this->post('/login', [
            'email' => 'a@club.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect();
    }

    public function test_password_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'a@club.test', 'password' => 'password']);

        $this->post('/login', ['email' => 'a@club.test', 'password' => 'wrong']);

        $this->assertGuest();
    }

    public function test_password_login_blocked_for_inactive_account(): void
    {
        // §4.3 : un compte désactivé (suppression demandée, accès coupé) ne peut pas ouvrir un
        // NOUVEAU login — même garde que le magic link et l'OAuth.
        User::factory()->create([
            'email' => 'gone@club.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => 'gone@club.test', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_password_login_is_case_insensitive_on_email(): void
    {
        $user = User::factory()->create(['email' => 'mixed@club.test', 'password' => 'password']);

        $this->post('/login', ['email' => 'Mixed@Club.Test', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_bootstrap_admin_promotes_matching_email(): void
    {
        config(['club.bootstrap_admin_email' => 'boss@club.test']);
        $user = User::factory()->create(['email' => 'boss@club.test', 'roles' => ['athlete']]);

        $promoted = BootstrapAdmin::promoteIfMatches($user);

        $this->assertTrue($promoted);
        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_bootstrap_admin_ignores_other_emails(): void
    {
        config(['club.bootstrap_admin_email' => 'boss@club.test']);
        $user = User::factory()->create(['email' => 'someone@club.test', 'roles' => ['athlete']]);

        $this->assertFalse(BootstrapAdmin::promoteIfMatches($user));
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_magic_link_token_is_single_use_and_hashed(): void
    {
        $user = User::factory()->create(['email' => 'm@club.test']);
        $url = MagicLink::createUrlFor('m@club.test');
        $token = (string) str($url)->afterLast('/');

        // Token stocké hashé, jamais en clair.
        $this->assertDatabaseMissing('magic_link_tokens', ['token_hash' => $token]);
        $this->assertNotNull(MagicLinkToken::where('token_hash', hash('sha256', $token))->first());

        // Première consommation OK, seconde refusée (usage unique).
        $this->assertSame('m@club.test', MagicLink::consume($token));
        $this->assertNull(MagicLink::consume($token));
    }

    public function test_magic_link_consume_route_logs_user_in(): void
    {
        $user = User::factory()->create(['email' => 'm@club.test']);
        $url = MagicLink::createUrlFor('m@club.test');

        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_magic_link_send_does_not_leak_account_existence(): void
    {
        // Email inexistant : on atterrit sur le MÊME écran de vérification qu'un compte connu
        // (§4.1.1), et aucun token n'est créé. Depuis l'ajout du code à usage unique, la neutralité
        // ne tient plus à un message flash mais à l'identité stricte de la redirection.
        $this->post('/magic-link', ['email' => 'ghost@club.test'])
            ->assertRedirect(route('magic-link.sent'));
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_prune_purges_expired_and_consumed_magic_link_tokens(): void
    {
        MagicLinkToken::create([ // utilisable → conservé
            'email' => 'a@club.test', 'token_hash' => hash('sha256', 'a'),
            'expires_at' => now()->addMinutes(10),
        ]);
        MagicLinkToken::create([ // expiré → purgé
            'email' => 'b@club.test', 'token_hash' => hash('sha256', 'b'),
            'expires_at' => now()->subMinute(),
        ]);
        MagicLinkToken::create([ // consommé → purgé
            'email' => 'c@club.test', 'token_hash' => hash('sha256', 'c'),
            'expires_at' => now()->addMinutes(10), 'consumed_at' => now(),
        ]);

        $this->artisan('model:prune', ['--model' => [MagicLinkToken::class]]);

        $this->assertDatabaseCount('magic_link_tokens', 1);
        $this->assertNotNull(MagicLinkToken::where('token_hash', hash('sha256', 'a'))->first());
    }
}
