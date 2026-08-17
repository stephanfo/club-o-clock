<?php

namespace Tests\Feature;

use App\Livewire\Profil;
use App\Models\AuthIdentity;
use App\Models\ClubSettings;
use App\Models\NotificationPreferences;
use App\Models\QuotaTag;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// J8.4 — Profil utilisateur : préférences notif (§4.15.3/.4), quotas (§4.10), connexion (§4.1.1),
// suppression de compte (§4.3 voie 1). Toujours sur le compte courant — aucune action sur un tiers.
class ProfilTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    // ── Accès ──

    public function test_renders_for_authenticated_user(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Profil::class)->assertOk();
    }

    public function test_guest_is_redirected_from_route(): void
    {
        $this->get('/profil')->assertRedirect();
    }

    // ── Identité ──

    public function test_save_identity_updates_own_name(): void
    {
        $u = User::factory()->create(['first_name' => 'Léa', 'last_name' => 'Pereira']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('first_name', 'Léane')
            ->set('last_name', 'Perez')
            ->call('saveIdentity')
            ->assertHasNoErrors();

        $this->assertSame('Léane', $u->fresh()->first_name);
        $this->assertSame('Perez', $u->fresh()->last_name);
    }

    public function test_save_identity_requires_names(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs($u)->test(Profil::class)
            ->set('first_name', '')
            ->call('saveIdentity')
            ->assertHasErrors(['first_name' => 'required']);
    }

    // ── Notifs : matrice (§4.15.3) + pause (§4.15.4) ──

    public function test_matrix_defaults_all_on(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Profil::class)
            ->assertSet('matrix.session_cancelled.push', true)
            ->assertSet('matrix.session_cancelled.email', true);
    }

    public function test_toggle_pref_opts_out_and_persists(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs($u)->test(Profil::class)
            ->call('togglePref', 'session_cancelled', 'push')
            ->assertSet('matrix.session_cancelled.push', false);

        $matrix = NotificationPreferences::where('user_id', $u->id)->first()->matrix;
        $this->assertFalse($matrix['session_cancelled']['push']);
        $this->assertTrue($matrix['session_cancelled']['email'] ?? true); // l'autre canal intact
    }

    public function test_toggle_pause_persists(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs($u)->test(Profil::class)
            ->call('togglePause')
            ->assertSet('paused', true);

        $this->assertTrue(NotificationPreferences::where('user_id', $u->id)->first()->paused);
    }

    public function test_coach_only_group_hidden_for_athlete(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Profil::class)
            ->set('tab', 'notifs')
            ->assertDontSee('Encadrement');
    }

    public function test_coach_only_group_visible_for_coach(): void
    {
        Livewire::actingAs(User::factory()->coach()->create())->test(Profil::class)
            ->set('tab', 'notifs')
            ->assertSee('Encadrement');
    }

    // ── Connexion : méthodes (§4.1.1) ──

    public function test_revoke_google_method_deletes_identity(): void
    {
        $u = User::factory()->create(['email' => 'lea@club.test']);
        $id = AuthIdentity::create([
            'user_id' => $u->id, 'provider' => 'google', 'provider_uid' => 'sub-1',
            'email_at_link' => 'lea@club.test', 'linked_at' => Carbon::now(),
        ]);

        Livewire::actingAs($u)->test(Profil::class)->call('revokeMethod', $id->id);

        $this->assertDatabaseMissing('auth_identities', ['id' => $id->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth_method_unlinked', 'actor_id' => $u->id]);
    }

    public function test_revoke_last_login_method_is_blocked(): void
    {
        // Aucun autre moyen : pas d'email, pas de mot de passe, une seule identité.
        $u = User::factory()->create(['email' => null, 'password' => null]);
        $id = AuthIdentity::create([
            'user_id' => $u->id, 'provider' => 'google', 'provider_uid' => 'sub-2',
            'email_at_link' => 'x@club.test', 'linked_at' => Carbon::now(),
        ]);

        Livewire::actingAs($u)->test(Profil::class)->call('revokeMethod', $id->id);

        $this->assertDatabaseHas('auth_identities', ['id' => $id->id]); // conservée
    }

    // ── Connexion : sessions actives ──

    public function test_revoke_other_sessions_keeps_current(): void
    {
        config(['session.driver' => 'database']);
        $u = User::factory()->create();
        DB::table('http_sessions')->insert([
            'id' => 'other-device', 'user_id' => $u->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0 (iPhone) Safari', 'payload' => 'x', 'last_activity' => time(),
        ]);

        Livewire::actingAs($u)->test(Profil::class)->call('revokeOtherSessions');

        $this->assertDatabaseMissing('http_sessions', ['id' => 'other-device']);
    }

    public function test_revoke_specific_session(): void
    {
        config(['session.driver' => 'database']);
        $u = User::factory()->create();
        DB::table('http_sessions')->insert([
            'id' => 'macbook', 'user_id' => $u->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Firefox', 'payload' => 'x', 'last_activity' => time(),
        ]);

        Livewire::actingAs($u)->test(Profil::class)->call('revokeSession', 'macbook');

        $this->assertDatabaseMissing('http_sessions', ['id' => 'macbook']);
    }

    public function test_revoking_sessions_rotates_remember_token(): void
    {
        // Sans rotation, un appareil « souviens-toi de moi » se reconnecte malgré la suppression
        // de sa ligne de session (les deux logins posent remember=true).
        config(['session.driver' => 'database']);
        $u = User::factory()->create();
        $u->forceFill(['remember_token' => 'stale-token'])->save();

        Livewire::actingAs($u)->test(Profil::class)->call('revokeOtherSessions');

        $this->assertNotSame('stale-token', $u->fresh()->remember_token);
    }

    // ── Quotas (§4.10) ──

    public function test_weekly_quota_usage_is_computed(): void
    {
        // Milieu de semaine club gelé : lancé un dimanche soir, « now + 2h » basculerait la séance
        // sur la semaine locale suivante — hors de l'usage hebdo affiché (test date-dépendant).
        $this->travelTo(Carbon::now(ClubSettings::current()->timezone)
            ->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(12, 0));

        $u = $this->athlete();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);
        $session = $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addHours(2), 'duration_min' => 60,
            'capacity' => 10, 'quota_tag_id' => $tag->id,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
        app(RegistrationService::class)->register($session, $u, $u);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('tab', 'quotas')
            ->assertSee('Piscine')
            ->assertSee('Natation seuil');
    }

    // ── Suppression de compte (§4.3 voie 1, choix pragmatique) ──

    public function test_request_deletion_opens_buffer(): void
    {
        $u = User::factory()->create(['is_active' => true]);

        Livewire::actingAs($u)->test(Profil::class)
            ->call('confirmDeleteAccount')
            ->assertSet('showDeleteDialog', true)
            ->call('requestDeletion')
            ->assertSet('showDeleteDialog', false);

        $fresh = $u->fresh();
        $this->assertTrue($fresh->isDeletionPending());
        $this->assertFalse($fresh->is_active); // bloque les futurs logins (§4.3)
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_deletion_requested', 'actor_id' => $u->id]);
    }

    public function test_cancel_deletion_retracts(): void
    {
        $u = User::factory()->create();
        Livewire::actingAs($u)->test(Profil::class)->call('requestDeletion');
        $this->assertTrue($u->fresh()->isDeletionPending());

        Livewire::actingAs($u)->test(Profil::class)->call('cancelDeletion');

        $fresh = $u->fresh();
        $this->assertFalse($fresh->isDeletionPending());
        $this->assertTrue($fresh->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_deletion_cancelled']);
    }

    public function test_last_active_admin_cannot_request_deletion(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Profil::class)
            ->call('requestDeletion')
            ->assertSet('showDeleteDialog', false);

        $fresh = $admin->fresh();
        $this->assertFalse($fresh->isDeletionPending()); // refusé
        $this->assertTrue($fresh->is_active);

        // UI préventive : bouton masqué, note affichée.
        Livewire::actingAs($admin)->test(Profil::class)
            ->set('tab', 'connexion')
            ->assertSee('dernier administrateur actif');
    }

    public function test_admin_can_request_deletion_when_a_peer_admin_exists(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create(); // pair admin actif

        Livewire::actingAs($admin)->test(Profil::class)->call('requestDeletion');

        $this->assertTrue($admin->fresh()->isDeletionPending());
    }
}
