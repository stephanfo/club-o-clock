<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Models\InvitationToken;
use App\Models\User;
use App\Services\GuardianshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

// J8.6 — Page d'activation du compte mineur autonomisé (PRD §4.1.3, §4.2.1 ; punted J7.7 → J8).
// Le pupille suit le lien reçu par email (guardianship_invitation), le jeton est consommé, il est
// connecté et son email vérifié. Le lien de tutelle (P2) reste en place.
class GuardianshipActivationTest extends TestCase
{
    use RefreshDatabase;

    private function ward(): User
    {
        $guardian = User::factory()->create(['is_minor' => false]);

        return User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'guardianship_linked_at' => Carbon::now(),
            'email' => 'ward@club.test',
            'email_verified_at' => null,
        ]);
    }

    private function tokenFor(User $ward, ?Carbon $expiresAt = null): string
    {
        $token = 'plain-token-value';
        InvitationToken::create([
            'user_id' => $ward->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt ?? Carbon::now()->addDays(7),
        ]);

        return $token;
    }

    public function test_valid_link_activates_logs_in_and_verifies_email(): void
    {
        $ward = $this->ward();
        $token = $this->tokenFor($ward);

        // Depuis §4.1.3, l'activation atterrit sur l'écran d'accueil qui laisse choisir sa méthode
        // de connexion (mot de passe optionnel), et non plus directement sur le dashboard.
        $this->get("/invitation/{$token}")->assertRedirect(route('activation'));

        $this->assertAuthenticatedAs($ward);
        $this->assertNotNull($ward->fresh()->email_verified_at);
        $this->assertNotNull(InvitationToken::where('user_id', $ward->id)->first()->consumed_at);
        // Le lien de tutelle est conservé (P2).
        $this->assertNotNull($ward->fresh()->guardian_id);
    }

    public function test_expired_link_is_refused(): void
    {
        $ward = $this->ward();
        $token = $this->tokenFor($ward, Carbon::now()->subDay());

        $this->get("/invitation/{$token}")->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($ward->fresh()->email_verified_at);
    }

    public function test_unknown_token_is_refused(): void
    {
        $this->get('/invitation/does-not-exist')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_already_consumed_token_is_refused(): void
    {
        $ward = $this->ward();
        $token = $this->tokenFor($ward);
        InvitationToken::where('user_id', $ward->id)->update(['consumed_at' => Carbon::now()]);

        $this->get("/invitation/{$token}")->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_autonomisation_is_cancelled_when_no_channel_can_carry_the_invitation(): void
    {
        // Sans le lien, l'enfant a un compte dont il ignore l'existence et qu'il ne peut pas
        // activer : l'autonomisation N'EST PAS l'ouverture d'un compte plus un mail en bonus.
        $admin = User::factory()->create(['roles' => ['admin']]);
        $garant = User::factory()->create(['email' => 'parent@club.test']);
        $enfant = User::factory()->create([
            'email' => null, 'is_minor' => true, 'guardian_id' => $garant->id,
            'guardianship_linked_at' => now(),
        ]);

        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        try {
            app(GuardianshipService::class)->invite($enfant, $admin, 'enfant@club.test');
            $this->fail('L\'autonomisation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('canal email', $e->getMessage());
        }

        // Tout est annulé : ni email posé, ni jeton, ni trace d'un acte qui n'a pas eu lieu.
        $enfant->refresh();
        $this->assertNull($enfant->email);
        $this->assertDatabaseCount('invitation_tokens', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'guardianship_invite_sent']);
    }
}
