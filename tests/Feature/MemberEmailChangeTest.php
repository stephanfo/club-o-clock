<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Models\InvitationToken;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\MemberService;
use App\Support\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// Correction de l'email de connexion depuis la fiche adhérent (PRD §4.1.3).
//
// Le trou que ces tests ferment : l'adresse saisie par le bureau est crue sur parole et marquée
// vérifiée d'office (c'est ce qui empêche le compte de naître muet). Une coquille donnait donc la
// prise du compte à un tiers — et n'avait AUCUN chemin de correction : l'écran affichait l'adresse
// en lecture seule, et l'invitation de 30 jours partie à la mauvaise adresse restait honorée.
class MemberEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin']]);
    }

    private function membre(string $email = 'coquille@club.test'): User
    {
        return app(MemberService::class)->create([
            'first_name' => 'Camille', 'last_name' => 'Roux',
            'dob' => '1990-05-04', 'email' => $email,
            'roles' => ['athlete'], 'surclassements' => [], 'qualifications' => [], 'guardian_id' => null,
        ], $this->admin());
    }

    // ── Ce que le changement révoque ──

    public function test_changing_the_address_revokes_the_live_invitation(): void
    {
        $membre = $this->membre();
        app(InvitationService::class)->mint($membre);
        $this->assertSame(1, InvitationToken::where('user_id', $membre->id)->count());

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        // Les jetons sont indexés par user_id : sans purge explicite, ils survivaient au changement
        // d'adresse et restaient honorés jusqu'à 30 jours au profit du destinataire par erreur.
        $this->assertSame(0, InvitationToken::where('user_id', $membre->id)->whereNull('consumed_at')->count());
    }

    public function test_changing_the_address_kills_the_magic_links_of_the_old_one(): void
    {
        $membre = $this->membre();
        MagicLink::issue($membre->email);

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertSame(0, MagicLinkToken::where('email', 'coquille@club.test')->count());
    }

    public function test_changing_the_address_kills_the_password_reset_tokens_of_the_old_one(): void
    {
        $membre = $this->membre();
        DB::table('password_reset_tokens')->insert([
            'email' => $membre->email, 'token' => hash('sha256', 'x'), 'created_at' => now(),
        ]);

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'coquille@club.test']);
    }

    public function test_consumed_invitations_survive_because_they_mark_activation(): void
    {
        // Contrôle positif apparié : la purge vise les jetons VIVANTS. Un jeton consommé est le
        // marqueur durable d'activation (cf. InvitationToken::prunable) — l'effacer ferait
        // repasser un adhérent entré depuis longtemps pour un compte à relancer.
        $membre = $this->membre();
        InvitationToken::create([
            'user_id' => $membre->id, 'token_hash' => hash('sha256', 'consomme'),
            'expires_at' => now()->addDay(), 'consumed_at' => now(),
        ]);

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertDatabaseHas('invitation_tokens', ['token_hash' => hash('sha256', 'consomme')]);
    }

    public function test_changing_the_address_closes_the_sessions_already_open(): void
    {
        // Le cœur du geste : le tiers qui a activé le compte depuis l'adresse erronée est DÉJÀ
        // entré. Révoquer les jetons sans couper sa session le laisserait connecté indéfiniment —
        // exactement le risque que la correction prétend fermer.
        $membre = $this->membre();
        DB::table('http_sessions')->insert([
            'id' => 'session-du-tiers', 'user_id' => $membre->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0 (iPhone) Safari', 'payload' => 'x', 'last_activity' => time(),
        ]);

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertDatabaseMissing('http_sessions', ['id' => 'session-du-tiers']);
    }

    public function test_changing_the_address_invalidates_the_remember_cookies(): void
    {
        // Supprimer la ligne de session ne suffit pas : les deux chemins de login posent un cookie
        // « se souvenir de moi », et Laravel ré-authentifierait l'appareil au passage suivant.
        $membre = $this->membre();
        $membre->forceFill(['remember_token' => str_repeat('a', 60)])->save();

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertNull($membre->refresh()->remember_token);
    }

    public function test_an_unchanged_address_leaves_the_sessions_alone(): void
    {
        // Contrôle positif apparié au test ci-dessus : rouvrir et réenregistrer sans rien changer
        // ne doit déconnecter personne.
        $membre = $this->membre();
        DB::table('http_sessions')->insert([
            'id' => 'session-legitime', 'user_id' => $membre->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Firefox', 'payload' => 'x', 'last_activity' => time(),
        ]);

        app(MemberService::class)->updateEmail($membre, 'coquille@club.test', $this->admin());

        $this->assertDatabaseHas('http_sessions', ['id' => 'session-legitime']);
    }

    // ── Ce que le changement rétablit ──

    public function test_the_new_address_is_verified_so_the_account_is_not_mute(): void
    {
        // Même raison qu'à la création : le lien magique exige un email vérifié (§4.1.1). Sans ça,
        // corriger l'adresse rendrait le compte inaccessible au lieu de le réparer.
        $membre = $this->membre();

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $membre->refresh();
        $this->assertSame('correct@club.test', $membre->email);
        $this->assertNotNull($membre->email_verified_at);
    }

    public function test_the_change_is_audited(): void
    {
        $membre = $this->membre();

        app(MemberService::class)->updateEmail($membre, 'correct@club.test', $this->admin());

        $this->assertDatabaseHas('audit_logs', ['action' => 'email_changed', 'target_id' => $membre->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'member_email_changed']);
    }

    public function test_an_unchanged_address_is_a_no_op(): void
    {
        // Rouvrir et réenregistrer sans rien toucher ne doit pas révoquer l'invitation en cours.
        $membre = $this->membre();
        app(InvitationService::class)->mint($membre);

        app(MemberService::class)->updateEmail($membre, 'coquille@club.test', $this->admin());

        $this->assertSame(1, InvitationToken::where('user_id', $membre->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'email_changed']);
    }

    // ── L'écran ──

    public function test_the_sheet_saves_a_corrected_address(): void
    {
        $membre = $this->membre();

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('editEmail')
            ->set('email', 'correct@club.test')
            ->call('saveEmail')
            ->assertHasNoErrors();

        $this->assertSame('correct@club.test', $membre->fresh()->email);
    }

    public function test_the_sheet_refuses_an_address_already_taken(): void
    {
        $membre = $this->membre();
        User::factory()->create(['email' => 'occupe@club.test']);

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('editEmail')
            ->set('email', 'occupe@club.test')
            ->call('saveEmail')
            ->assertHasErrors('email');

        $this->assertSame('coquille@club.test', $membre->fresh()->email);
    }

    public function test_the_sheet_refuses_to_empty_the_address(): void
    {
        // Vider l'adresse d'un pupille serait une bascule P2 → P1 silencieuse. La rupture de
        // tutelle a son propre geste, avec ses conséquences affichées.
        $membre = $this->membre();

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('editEmail')
            ->set('email', '')
            ->call('saveEmail')
            ->assertHasErrors('email');

        $this->assertSame('coquille@club.test', $membre->fresh()->email);
    }

    public function test_the_sheet_keeps_the_same_address_without_error(): void
    {
        // Contrôle positif apparié à la règle d'unicité : sa propre adresse n'est pas un doublon.
        $membre = $this->membre();

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('editEmail')
            ->set('email', 'coquille@club.test')
            ->call('saveEmail')
            ->assertHasNoErrors();
    }

    public function test_a_non_admin_cannot_reach_the_sheet_at_all(): void
    {
        $membre = $this->membre();

        foreach ([['athlete'], ['coach']] as $roles) {
            Livewire::actingAs(User::factory()->create(['roles' => $roles]))
                ->test(MemberShow::class, ['user' => $membre])
                ->assertForbidden();
        }
    }
}
