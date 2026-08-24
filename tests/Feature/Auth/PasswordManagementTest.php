<?php

namespace Tests\Feature\Auth;

use App\Livewire\Profil;
use App\Models\AuthIdentity;
use App\Models\ClubSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

// Pose / changement / retrait du mot de passe depuis le profil (PRD §4.1.1, §4.1.5).
// Le mot de passe est une méthode parmi d'autres : un compte créé par invitation n'en a pas, et
// n'est pas tenu d'en avoir un — d'où la dissymétrie « poser » (sans ancien) / « changer » (avec).
class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    private function setSettings(array $attributes): void
    {
        ClubSettings::current()->update($attributes);
        ClubSettings::flushCache();
    }

    /** Compte passwordless, tel qu'en produit une activation d'invitation (§4.1.3). */
    private function passwordless(): User
    {
        return User::factory()->create(['password' => null, 'email' => 'sans@club.test']);
    }

    /** Ligne de session d'un AUTRE appareil du même utilisateur. */
    private function otherSession(User $u, string $id = 'autre-appareil'): void
    {
        DB::table(config('session.table'))->insert([
            'id' => $id,
            'user_id' => $u->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Firefox',
            'payload' => base64_encode('x'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    // ── Poser un premier mot de passe ──

    public function test_passwordless_account_sets_password_without_current_password(): void
    {
        $u = $this->passwordless();

        Livewire::actingAs($u)->test(Profil::class)
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('motdepassesolide', $u->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_set', 'target_id' => $u->id]);
    }

    public function test_setting_first_password_keeps_other_sessions(): void
    {
        // Ajouter une sécurité n'est pas un signal de compromission : déconnecter tous ses appareils
        // pour ça serait punitif, et découragerait le geste qu'on cherche à encourager.
        $u = $this->passwordless();
        $this->otherSession($u);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas(config('session.table'), ['id' => 'autre-appareil']);
    }

    // ── Changer un mot de passe existant ──

    public function test_changing_password_requires_the_current_one(): void
    {
        $u = User::factory()->create(['password' => 'password']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'pasbon')
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $u->fresh()->password));
    }

    public function test_account_with_password_cannot_skip_current_password(): void
    {
        // Le contournement à fermer : prétendre être passwordless en omettant le champ.
        $u = User::factory()->create(['password' => 'password']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $u->fresh()->password));
    }

    public function test_changing_password_revokes_other_sessions_by_default(): void
    {
        $u = User::factory()->create(['password' => 'password']);
        $this->otherSession($u);
        $ancienJeton = $u->remember_token;

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing(config('session.table'), ['id' => 'autre-appareil']);
        $this->assertNotSame($ancienJeton, $u->fresh()->remember_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'target_id' => $u->id]);
    }

    public function test_changing_password_keeps_other_sessions_when_unchecked(): void
    {
        $u = User::factory()->create(['password' => 'password']);
        $this->otherSession($u);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->set('revokeOthers', false)
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas(config('session.table'), ['id' => 'autre-appareil']);
    }

    // ── Politique de robustesse (§4.1.1) ──

    public function test_password_shorter_than_ten_characters_is_refused(): void
    {
        $u = $this->passwordless();

        Livewire::actingAs($u)->test(Profil::class)
            ->set('password', '123456789')
            ->set('password_confirmation', '123456789')
            ->call('savePassword')
            ->assertHasErrors('password');

        $this->assertNull($u->fresh()->password);
    }

    public function test_password_must_be_confirmed(): void
    {
        $u = $this->passwordless();

        Livewire::actingAs($u)->test(Profil::class)
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'autrechose1234')
            ->call('savePassword')
            ->assertHasErrors('password');

        $this->assertNull($u->fresh()->password);
    }

    // ── Retirer le mot de passe ──

    public function test_removing_password_succeeds_when_magic_link_remains(): void
    {
        $u = User::factory()->create(['password' => 'password', 'email' => 'a@club.test']);
        $this->otherSession($u);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->call('removePassword')
            ->assertHasNoErrors();

        $this->assertNull($u->fresh()->password);
        $this->assertDatabaseMissing(config('session.table'), ['id' => 'autre-appareil']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_removed', 'target_id' => $u->id]);
    }

    public function test_removing_password_refused_without_email_nor_oauth(): void
    {
        // Direction 1 : plus rien derrière — ni email, ni identité liée.
        $u = User::factory()->create(['password' => 'password', 'email' => null]);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->call('removePassword');

        $this->assertNotNull($u->fresh()->password);
    }

    public function test_removing_password_refused_when_club_closed_magic_link(): void
    {
        // Direction 2 : l'email ne sauve rien si le club a coupé le lien magique (§4.17).
        $this->setSettings(['auth_magic_link_enabled' => false]);
        $u = User::factory()->create(['password' => 'password', 'email' => 'a@club.test']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->call('removePassword');

        $this->assertNotNull($u->fresh()->password);
    }

    public function test_removing_password_refused_when_club_closed_google(): void
    {
        // Direction 3 : une identité Google ne sauve rien si Google est coupé (§4.17).
        $this->setSettings(['auth_magic_link_enabled' => false, 'auth_google_enabled' => false]);
        $u = User::factory()->create(['password' => 'password', 'email' => 'a@club.test']);
        AuthIdentity::create([
            'user_id' => $u->id,
            'provider' => 'google',
            'provider_uid' => 'uid-1',
            'email_at_link' => 'a@club.test',
            'linked_at' => now(),
        ]);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->call('removePassword');

        $this->assertNotNull($u->fresh()->password);
    }

    public function test_removing_password_requires_the_current_one(): void
    {
        $u = User::factory()->create(['password' => 'password', 'email' => 'a@club.test']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'pasbon')
            ->call('removePassword')
            ->assertHasErrors('current_password');

        $this->assertNotNull($u->fresh()->password);
    }

    // ── Mode démo ──

    public function test_demo_mode_refuses_password_changes(): void
    {
        // Le mot de passe de démo est public et partagé : le laisser changer verrouillerait tous
        // les visiteurs suivants jusqu'à la remise à zéro nocturne.
        config(['club.demo.enabled' => true]);
        $u = User::factory()->create(['password' => 'password']);

        Livewire::actingAs($u)->test(Profil::class)
            ->set('current_password', 'password')
            ->set('password', 'motdepassesolide')
            ->set('password_confirmation', 'motdepassesolide')
            ->call('savePassword');

        $this->assertTrue(Hash::check('password', $u->fresh()->password));
    }
}
