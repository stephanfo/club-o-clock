<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Amorçage du premier admin (club:create-admin). Cette commande est le SEUL chemin d'entrée d'une
// instance fraîche : l'inscription publique est désactivée et le lien magique exige un compte
// existant. Ces tests verrouillent l'invariant « une base vide reste accessible à son exploitant ».
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_first_admin_on_empty_database(): void
    {
        $this->artisan('club:create-admin', [
            'email' => 'Boss@Club.test',
            '--first-name' => 'Jean',
            '--last-name' => 'Dupont',
            '--password' => 'motdepasse123',
        ])->assertSuccessful();

        $user = User::whereRaw('LOWER(email) = ?', ['boss@club.test'])->firstOrFail();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at, 'Le compte CLI doit être vérifié : sinon la vérification exigerait un email déjà configuré.');
        $this->assertSame('boss@club.test', $user->email, "L'email est normalisé en minuscules.");
        $this->assertTrue(Hash::check('motdepasse123', $user->password));
    }

    public function test_created_admin_can_actually_log_in(): void
    {
        $this->artisan('club:create-admin', [
            'email' => 'boss@club.test',
            '--first-name' => 'Jean',
            '--last-name' => 'Dupont',
            '--password' => 'motdepasse123',
        ])->assertSuccessful();

        $response = $this->post('/login', [
            'email' => 'boss@club.test',
            'password' => 'motdepasse123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect();
    }

    public function test_falls_back_to_bootstrap_admin_email_from_config(): void
    {
        config(['club.bootstrap_admin_email' => 'president@club.test']);

        $this->artisan('club:create-admin', [
            '--first-name' => 'Marie',
            '--last-name' => 'Martin',
            '--password' => 'motdepasse123',
        ])->assertSuccessful();

        $this->assertTrue(
            User::whereRaw('LOWER(email) = ?', ['president@club.test'])->firstOrFail()->hasRole('admin'),
        );
    }

    public function test_fails_without_any_email(): void
    {
        config(['club.bootstrap_admin_email' => null]);

        $this->artisan('club:create-admin', ['--password' => 'motdepasse123'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_promotes_existing_account_without_touching_its_password(): void
    {
        $user = User::factory()->create([
            'email' => 'coach@club.test',
            'password' => 'ancien-mot-de-passe',
            'roles' => ['coach'],
        ]);

        $this->artisan('club:create-admin', [
            'email' => 'coach@club.test',
            '--password' => 'un-autre-mot-de-passe',
        ])->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('coach'), 'La promotion ajoute le rôle admin, elle ne remplace pas les rôles existants.');
        $this->assertTrue(
            Hash::check('ancien-mot-de-passe', $user->password),
            'Promouvoir un compte existant ne doit jamais réécrire son mot de passe.',
        );
    }

    public function test_is_idempotent_on_an_existing_admin(): void
    {
        User::factory()->create(['email' => 'boss@club.test', 'roles' => ['admin']]);

        $this->artisan('club:create-admin', ['email' => 'boss@club.test'])->assertSuccessful();

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['boss@club.test'])->count());
    }

    /**
     * Revue open source 2026-08-08, constat n°7 — la commande est documentée comme le moyen de
     * « récupérer la main sur une instance dont l'admin s'est verrouillé », mais elle ne
     * rétablissait pas is_active : elle répondait « déjà administrateur » en code SUCCESS tout en
     * laissant l'exploitant dehors. Échec silencieux sur le SEUL point d'entrée d'une instance.
     */
    public function test_reactivates_a_disabled_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'boss@club.test',
            'roles' => ['admin'],
            'is_active' => false,
            'password' => 'motdepasse123',
        ]);

        $this->artisan('club:create-admin', ['email' => 'boss@club.test'])->assertSuccessful();

        $this->assertTrue($user->refresh()->is_active, 'Le compte doit être réactivé, sinon la commande ment.');
    }

    /** Le test qui compte vraiment : après la commande, l'exploitant peut se reconnecter. */
    public function test_a_disabled_admin_can_log_in_again_after_the_command(): void
    {
        User::factory()->create([
            'email' => 'boss@club.test',
            'roles' => ['admin'],
            'is_active' => false,
            'password' => Hash::make('motdepasse123'),
            'email_verified_at' => null,
        ]);

        $this->artisan('club:create-admin', ['email' => 'boss@club.test'])->assertSuccessful();

        $this->post('/login', ['email' => 'boss@club.test', 'password' => 'motdepasse123']);

        $this->assertAuthenticated();
    }

    public function test_rejects_an_invalid_email(): void
    {
        $this->artisan('club:create-admin', [
            'email' => 'pas-un-email',
            '--password' => 'motdepasse123',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_a_too_short_password(): void
    {
        $this->artisan('club:create-admin', [
            'email' => 'boss@club.test',
            '--first-name' => 'Jean',
            '--last-name' => 'Dupont',
            '--password' => 'court',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
