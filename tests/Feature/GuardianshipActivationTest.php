<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
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

    public function test_autonomisation_proceeds_when_the_club_email_channel_is_off(): void
    {
        // L'invitation de tutelle porte le lien d'activation, comme celle d'adhérent : elle relève
        // de l'accès au compte (§4.15.1) et traverse l'interrupteur de canal (§4.17).
        $admin = User::factory()->create(['roles' => ['admin']]);
        $garant = User::factory()->create(['email' => 'parent@club.test']);
        $enfant = User::factory()->create([
            'email' => null, 'is_minor' => true, 'guardian_id' => $garant->id,
            'guardianship_linked_at' => now(),
        ]);

        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        app(GuardianshipService::class)->invite($enfant, $admin, 'enfant@club.test');

        // L'acte a bien eu lieu de bout en bout : email posé, jeton frappé, trace écrite.
        $enfant->refresh();
        $this->assertSame('enfant@club.test', $enfant->email);
        $this->assertDatabaseCount('invitation_tokens', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_invite_sent']);

        // L'assertion qui porte réellement le correctif : la LIGNE d'outbox. Tout ce qui précède
        // est écrit dans la transaction, AVANT le dispatch — une régression de l'exemption les
        // laisserait tous verts pour un mail jamais parti.
        $this->assertSame(1, NotificationOutbox::where('type', 'guardianship_invitation')
            ->where('user_id', $enfant->id)->where('channel', 'email')->count());
    }

    public function test_autonomisation_is_refused_for_an_inactive_ward(): void
    {
        // L'exemption porte sur le consentement, jamais sur la joignabilité (§4.15.1) : un compte
        // dont la suppression est engagée ne s'ouvre pas. Sans cette garde, on frappait un jeton de
        // 30 jours et on écrivait un audit d'envoi pour un compte qu'on est en train d'effacer.
        $admin = User::factory()->create(['roles' => ['admin']]);
        $garant = User::factory()->create(['email' => 'parent3@club.test']);
        $enfant = User::factory()->create([
            'email' => null, 'is_minor' => true, 'guardian_id' => $garant->id,
            'guardianship_linked_at' => now(), 'is_active' => false,
        ]);

        try {
            app(GuardianshipService::class)->invite($enfant, $admin, 'inactif@club.test');
            $this->fail('L\'autonomisation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('n\'est pas actif', $e->getMessage());
        }

        $this->assertDatabaseCount('invitation_tokens', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'guardianship_invite_sent']);
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_autonomisation_is_refused_for_an_anonymised_ward(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $garant = User::factory()->create(['email' => 'parent4@club.test']);
        $enfant = User::factory()->create([
            'email' => null, 'is_minor' => true, 'guardian_id' => $garant->id,
            'guardianship_linked_at' => now(), 'anonymized_at' => now(),
        ]);

        try {
            app(GuardianshipService::class)->invite($enfant, $admin, 'anon@club.test');
            $this->fail('L\'autonomisation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('anonymisé', $e->getMessage());
        }

        $this->assertDatabaseCount('invitation_tokens', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'guardianship_invite_sent']);
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_autonomisation_is_still_cancelled_without_an_email(): void
    {
        // Contrôle positif apparié : la garde d'annulation totale reste vivante pour ce qui tient
        // à la joignabilité — sans email, l'enfant ne peut pas être invité.
        $admin = User::factory()->create(['roles' => ['admin']]);
        $garant = User::factory()->create(['email' => 'parent2@club.test']);
        $enfant = User::factory()->create([
            'email' => null, 'is_minor' => true, 'guardian_id' => $garant->id,
            'guardianship_linked_at' => now(),
        ]);

        try {
            app(GuardianshipService::class)->invite($enfant, $admin, null);
            $this->fail('L\'autonomisation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $enfant->refresh();
        $this->assertNull($enfant->email);
        $this->assertDatabaseCount('invitation_tokens', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'guardianship_invite_sent']);
    }
}
