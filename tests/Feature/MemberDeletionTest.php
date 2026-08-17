<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\MemberShow;
use App\Livewire\Home;
use App\Models\AuditLog;
use App\Models\AuthIdentity;
use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\MemberService;
use App\Support\Logging\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Suppression de compte + anonymisation RGPD J6.3 (PRD §4.3, §4.18.3) :
// tampon 7 j bloquant (UI ET serveur), annulation, anonymisation tombstone, 3 signaux passifs.
class MemberDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function eligibleSince(User $member, int $daysAgo = 8): User
    {
        // Demande posée il y a $daysAgo jours → tampon J+7 écoulé si $daysAgo >= 7.
        $member->forceFill([
            'deletion_requested_at' => Carbon::now()->subDays($daysAgo),
            'is_active' => false,
        ])->save();

        return $member->refresh();
    }

    // ── Helpers de modèle (sémantique tampon) ──

    public function test_eligibility_helpers_distinguish_pending_from_eligible(): void
    {
        $fresh = User::factory()->create();
        $this->assertFalse($fresh->isDeletionPending());
        $this->assertFalse($fresh->isDeletionEligible());

        $inBuffer = $this->eligibleSince(User::factory()->create(), 3);
        $this->assertTrue($inBuffer->isDeletionPending());
        $this->assertFalse($inBuffer->isDeletionEligible());

        $past = $this->eligibleSince(User::factory()->create(), 8);
        $this->assertTrue($past->isDeletionPending());
        $this->assertTrue($past->isDeletionEligible());
    }

    // ── Service : voies de cycle de vie ──

    public function test_request_deletion_opens_buffer_disables_account_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['is_active' => true]);

        app(MemberService::class)->requestDeletion($member, $admin);
        $member->refresh();

        $this->assertNotNull($member->deletion_requested_at);
        $this->assertFalse($member->is_active);
        $this->assertNull($member->anonymized_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_deletion_requested',
            'actor_id' => $admin->id,
            'target_id' => $member->id,
        ]);
    }

    public function test_request_deletion_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 2);
        $first = $member->deletion_requested_at;

        app(MemberService::class)->requestDeletion($member, $admin);

        $this->assertEquals($first, $member->fresh()->deletion_requested_at);
        $this->assertSame(0, AuditLog::where('action', 'account_deletion_requested')->count());
    }

    public function test_cancel_deletion_reactivates_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 3);

        app(MemberService::class)->cancelDeletion($member, $admin);
        $member->refresh();

        $this->assertNull($member->deletion_requested_at);
        $this->assertTrue($member->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_deletion_cancelled',
            'actor_id' => $admin->id,
            'target_id' => $member->id,
        ]);
    }

    public function test_confirm_deletion_is_blocked_before_buffer(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 3); // J+3 < J+7

        $this->expectException(RuntimeException::class);
        app(MemberService::class)->confirmDeletion($member, $admin);
    }

    public function test_confirm_deletion_anonymizes_tombstone_and_purges_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'first_name' => 'Camille',
            'last_name' => 'Vincent',
            'email' => 'camille@example.test',
        ]);
        AuthIdentity::create(['user_id' => $member->id, 'provider' => 'google', 'provider_uid' => 'sub-123', 'email_at_link' => 'camille@example.test', 'linked_at' => Carbon::now()]);
        NotificationPreferences::create(['user_id' => $member->id, 'matrix' => [], 'paused' => false]);
        DB::table('password_reset_tokens')->insert(['email' => 'camille@example.test', 'token' => 'x', 'created_at' => Carbon::now()]);
        PushSubscription::create([
            'user_id' => $member->id, 'endpoint' => 'https://push.example/ep-1',
            'endpoint_hash' => hash('sha256', 'https://push.example/ep-1'), 'p256dh' => 'k', 'auth' => 'a',
        ]);
        NotificationOutbox::create(['type' => 'session_cancelled', 'channel' => 'push', 'payload' => [], 'user_id' => $member->id, 'status' => 'pending']);
        NotificationOutbox::create(['type' => 'session_cancelled', 'channel' => 'email', 'payload' => [], 'user_id' => $member->id, 'status' => 'sent', 'sent_at' => Carbon::now()]);
        $member = $this->eligibleSince($member, 8);

        app(MemberService::class)->confirmDeletion($member, $admin);
        $member->refresh();

        // PII effacées, ligne conservée (tombstone) avec anonymized_at.
        $this->assertSame('Compte', $member->first_name);
        $this->assertSame('supprimé', $member->last_name);
        $this->assertNull($member->email);
        $this->assertNull($member->password);
        $this->assertNull($member->dob);
        $this->assertFalse($member->is_active);
        $this->assertNotNull($member->anonymized_at);

        // Credentials purgés.
        $this->assertDatabaseMissing('auth_identities', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'camille@example.test']);

        // Non-contact après effacement : endpoints push (appareils) et lignes outbox pending
        // purgés ; l'historique déjà envoyé reste (rétention assumée de l'écran envois §4.15.6).
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('notification_outbox', ['user_id' => $member->id, 'status' => 'pending']);
        $this->assertDatabaseHas('notification_outbox', ['user_id' => $member->id, 'status' => 'sent']);

        // AuditLog account_deleted : actor = admin qui confirme, jamais system (§4.3).
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_deleted',
            'actor_id' => $admin->id,
            'target_id' => $member->id,
        ]);
    }

    public function test_confirm_deletion_on_already_anonymized_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 8);
        app(MemberService::class)->confirmDeletion($member, $admin);
        $member->refresh();

        // Ré-entrance : anonymized_at posé → isDeletionEligible() false → garde tient.
        $this->expectException(RuntimeException::class);
        app(MemberService::class)->confirmDeletion($member, $admin);
    }

    public function test_anonymization_preserves_log_correlation_via_stable_id(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 8);

        // Action passée tracée AVEC le compte comme acteur.
        $past = AuditLogger::record('role_changed', $member, [
            'target_type' => User::class,
            'target_id' => $member->id,
        ]);

        app(MemberService::class)->confirmDeletion($member, $admin);

        // La ligne de log garde le même actor_id (corrélation), mais pointe désormais le tombstone.
        $reloaded = AuditLog::find($past->id);
        $this->assertSame($member->id, $reloaded->actor_id);
        $this->assertSame('Compte', $reloaded->actor->first_name); // identité effacée
        $this->assertSame('athlete', $reloaded->actor_role); // snapshot du rôle conservé
    }

    // ── UI : modale demande + garde serveur ──

    public function test_request_modal_requires_exact_full_name(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Vincent']);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->set('deleteConfirmName', 'Mauvais Nom')
            ->call('requestDeletion')
            ->assertHasErrors('deleteConfirmName');

        $this->assertNull($member->fresh()->deletion_requested_at);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->set('deleteConfirmName', 'Camille Vincent')
            ->call('requestDeletion')
            ->assertHasNoErrors();

        $this->assertNotNull($member->fresh()->deletion_requested_at);
    }

    public function test_confirm_deletion_guarded_server_side_before_j7(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 3); // dans le tampon

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('confirmDeletion')
            ->assertHasErrors('deletion');

        $this->assertNull($member->fresh()->anonymized_at);
    }

    public function test_confirm_deletion_after_j7_anonymizes_and_redirects(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->eligibleSince(User::factory()->create(), 8);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('confirmDeletion')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.members'));

        $this->assertNotNull($member->fresh()->anonymized_at);
    }

    // ── Signaux passifs ──

    public function test_list_counts_and_filters_separate_pending_from_eligible(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = $this->eligibleSince(User::factory()->create(['first_name' => 'Tampon', 'last_name' => 'Encours']), 3);
        $eligible = $this->eligibleSince(User::factory()->create(['first_name' => 'Eligi', 'last_name' => 'Ble']), 8);

        Livewire::actingAs($admin)->test(MemberList::class)
            ->call('setAccess', 'eligible')
            ->assertSee('Eligi Ble')
            ->assertDontSee('Tampon Encours')
            ->call('setAccess', 'pending')
            ->assertSee('Tampon Encours')
            ->assertSee('Eligi Ble'); // pending = tampon + au-delà
    }

    public function test_home_banner_shows_for_admin_only_when_eligible_exists(): void
    {
        ClubSettings::current();
        $admin = User::factory()->admin()->create();
        $this->eligibleSince(User::factory()->create(), 8);

        Livewire::actingAs($admin)->test(Home::class)
            ->assertSee('éligible')
            ->assertSeeHtml(route('admin.members', ['access' => 'eligible']));

        $athlete = User::factory()->create(['roles' => ['athlete']]);
        Livewire::actingAs($athlete)->test(Home::class)
            ->assertDontSee('éligible à suppression');
    }
}
