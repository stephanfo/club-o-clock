<?php

namespace Tests\Feature\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

// Linking Google (PRD §4.1.2) : auto-link uniquement si l'email est vérifié DES DEUX CÔTÉS —
// compte club (email_verified_at) ET claim Google `email_verified` du userinfo OIDC.
class OAuthLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function mockGoogleUser(string $email, bool $claimVerified, string $uid = 'g-123'): void
    {
        $su = (new SocialiteUser)->map(['id' => $uid, 'email' => $email, 'name' => 'Demo User']);
        $su->user = ['email_verified' => $claimVerified];

        Socialite::shouldReceive('driver->user')->andReturn($su);
    }

    public function test_linking_succeeds_when_both_sides_verified(): void
    {
        $user = User::factory()->create(['email' => 'marie@demo.club']); // email_verified_at par factory
        $this->mockGoogleUser('marie@demo.club', claimVerified: true);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id, 'provider' => 'google', 'provider_uid' => 'g-123',
        ]);
    }

    public function test_linking_refused_when_google_claim_unverified(): void
    {
        User::factory()->create(['email' => 'marie@demo.club']);
        $this->mockGoogleUser('marie@demo.club', claimVerified: false);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, AuthIdentity::count());
    }

    public function test_linking_refused_when_local_email_unverified(): void
    {
        User::factory()->unverified()->create(['email' => 'marie@demo.club']);
        $this->mockGoogleUser('marie@demo.club', claimVerified: true);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, AuthIdentity::count());
    }

    public function test_already_linked_identity_logs_in_directly(): void
    {
        $user = User::factory()->create(['email' => 'marie@demo.club']);
        AuthIdentity::create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_uid' => 'g-123',
            'email_at_link' => 'marie@demo.club', 'linked_at' => now(),
        ]);
        // Identité déjà liée : la confiance est établie, le claim n'est pas re-exigé.
        $this->mockGoogleUser('marie@demo.club', claimVerified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
