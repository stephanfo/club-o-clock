<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\GuardianshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Cycle de vie des comptes mineurs P1→P2→P3 (PRD §4.2).
class GuardianshipTest extends TestCase
{
    use RefreshDatabase;

    private function family(?string $childEmail = null): array
    {
        $parent = User::factory()->create(['is_minor' => false]);
        $child = User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $parent->id,
            'guardianship_linked_at' => Carbon::now(),
            'email' => $childEmail,
        ]);

        return [$parent, $child];
    }

    public function test_invite_sets_email_and_creates_token(): void
    {
        [$parent, $child] = $this->family();
        $admin = User::factory()->admin()->create();

        $token = app(GuardianshipService::class)->invite($child, $admin, 'enfant@example.test');

        $this->assertNotEmpty($token);
        $this->assertSame('enfant@example.test', $child->fresh()->email);
        $this->assertSame(1, InvitationToken::where('user_id', $child->id)->whereNull('consumed_at')->count());
        // Le lien de tutelle est conservé (§4.2.1).
        $this->assertSame($parent->id, $child->fresh()->guardian_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_invite_sent', 'target_id' => $child->id]);
    }

    public function test_invite_requires_email(): void
    {
        [, $child] = $this->family();
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->invite($child, $admin);
    }

    public function test_invite_rejects_duplicate_email(): void
    {
        [, $child] = $this->family();
        User::factory()->create(['email' => 'taken@example.test']);
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->invite($child, $admin, 'TAKEN@example.test');
    }

    public function test_invite_rejects_non_minor(): void
    {
        $adult = User::factory()->create(['is_minor' => false]);
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->invite($adult, $admin, 'x@example.test');
    }

    // Un pupille devenu MAJEUR garde son garant (MemberService::updateDob) : invite() le refuse,
    // le bouton « Accès autonome » ne doit donc plus être offert — ni au parent, ni à l'admin.
    // Le rôle coach n'entre pas en jeu ici : c'est la tutelle, pas l'encadrement.
    public function test_autonomy_cta_hidden_for_a_major_ward(): void
    {
        [$parent, $child] = $this->family();
        $child->forceFill(['is_minor' => false, 'email' => null])->save();
        $admin = User::factory()->admin()->create();

        // Fiche admin : bandeau explicatif, plus de formulaire d'invitation.
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $child->fresh()])
            ->assertDontSeeHtml('wire:click="inviteWard"')
            ->assertSee('est <b>majeur</b>', escape: false);
    }

    // Contrôle positif appairé : tant que le pupille est mineur, l'autonomisation reste offerte.
    public function test_autonomy_cta_visible_for_a_minor_ward(): void
    {
        [$parent, $child] = $this->family();
        $child->forceFill(['email' => null])->save(); // is_minor reste true
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $child->fresh()])
            ->assertSeeHtml('wire:click="inviteWard"');
    }

    public function test_invite_invalidates_previous_pending(): void
    {
        [, $child] = $this->family('enfant@example.test');
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->invite($child, $admin);
        app(GuardianshipService::class)->invite($child, $admin);

        $this->assertSame(1, InvitationToken::where('user_id', $child->id)->whereNull('consumed_at')->count());
    }

    public function test_sever_breaks_link(): void
    {
        [, $child] = $this->family('enfant@example.test');
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->sever($child, $admin);

        $child->refresh();
        $this->assertNull($child->guardian_id);
        $this->assertNull($child->guardianship_linked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_severed', 'target_id' => $child->id]);
    }

    public function test_can_sever_rules(): void
    {
        [$parent, $child] = $this->family('enfant@example.test'); // P2
        $admin = User::factory()->admin()->create();
        $stranger = User::factory()->create();

        $this->assertTrue(GuardianshipService::canSever($admin, $child));
        $this->assertTrue(GuardianshipService::canSever($parent, $child));
        $this->assertTrue(GuardianshipService::canSever($child, $child)); // enfant en P2
        $this->assertFalse(GuardianshipService::canSever($stranger, $child));

        // Enfant P1 (sans email) ne peut pas se libérer lui-même (sinon captif).
        [, $p1] = $this->family();
        $this->assertFalse(GuardianshipService::canSever($p1, $p1));
    }

    public function test_admin_invites_via_member_show(): void
    {
        [, $child] = $this->family();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $child])
            ->set('wardEmail', 'ado@example.test')
            ->call('inviteWard');

        $this->assertSame('ado@example.test', $child->fresh()->email);
        $this->assertDatabaseHas('invitation_tokens', ['user_id' => $child->id]);
    }

    public function test_admin_severs_via_member_show(): void
    {
        [, $child] = $this->family('ado@example.test');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $child])
            ->call('severGuardianship')
            ->assertSet('confirmingSever', false);

        $this->assertNull($child->fresh()->guardian_id);
    }

    // ── J8.5 : notifs transactionnelles de tutelle (hors matrice) ──

    public function test_invite_emits_invitation_to_ward_only(): void
    {
        [$parent, $child] = $this->family();
        $admin = User::factory()->admin()->create();

        $token = app(GuardianshipService::class)->invite($child, $admin, 'enfant@example.test');

        // Lien d'activation au pupille seul (email), pas au garant.
        $this->assertSame(1, NotificationOutbox::where('type', 'guardianship_invitation')
            ->where('user_id', $child->id)->where('channel', 'email')->count());
        $this->assertSame(0, NotificationOutbox::where('user_id', $parent->id)->count());
        // Le token clair est porté par le payload (consommé à l'envoi, J8.6).
        $line = NotificationOutbox::where('type', 'guardianship_invitation')->first();
        $this->assertSame($token, $line->payload['token']);
    }

    public function test_sever_notifies_both_parties(): void
    {
        [$parent, $child] = $this->family('ado@example.test'); // P2 : enfant avec email propre
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->sever($child, $admin);

        // push + email à chacun = 4 lignes guardianship_severed.
        $this->assertSame(2, NotificationOutbox::where('type', 'guardianship_severed')
            ->where('user_id', $child->id)->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'guardianship_severed')
            ->where('user_id', $parent->id)->count());
    }

    // Revue de code — la rupture d'un P1 laissait un User sans garant ET sans moyen de connexion
    // (ni l'enfant, sans credential, ni le parent, détaché). Le refus vaut pour TOUS les acteurs,
    // admin compris : canSever ne l'appliquait qu'à l'enfant lui-même.
    public function test_sever_refused_on_p1_ward_without_own_account(): void
    {
        [$parent, $child] = $this->family(); // P1 : enfant sans email
        $admin = User::factory()->admin()->create();

        try {
            app(GuardianshipService::class)->sever($child, $admin);
            $this->fail('Attendu : refus de rompre la tutelle d\'un P1.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('compte propre', $e->getMessage());
        }

        // Rien n'a bougé : lien intact, aucune trace, aucune notif émise.
        $this->assertSame($parent->id, $child->fresh()->guardian_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'guardianship_severed', 'target_id' => $child->id,
        ]);
        $this->assertSame(0, NotificationOutbox::where('type', 'guardianship_severed')->count());
    }

    // Contrôle positif apparié : une fois l'enfant autonomisé (P1 → P2), la rupture redevient
    // possible — la garde vise la phase, pas le pupille. Sans ce test, le refus ci-dessus passerait
    // même si sever() refusait TOUT.
    public function test_sever_allowed_once_ward_has_own_account(): void
    {
        [, $child] = $this->family(); // P1
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->invite($child, $admin, 'ado@example.test'); // P1 → P2
        app(GuardianshipService::class)->sever($child->fresh(), $admin);

        $this->assertNull($child->fresh()->guardian_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'guardianship_severed', 'target_id' => $child->id,
        ]);
    }

    // Contre-garde : un pupille DEVENU MAJEUR en gardant son garant n'a plus accès à invite()
    // (qui exige un mineur). Étendre le refus à lui le rendrait définitivement captif — soit le
    // défaut même qu'on corrige. Pour lui, la rupture est la sortie prévue.
    public function test_sever_allowed_on_adult_ward_without_email(): void
    {
        [, $ward] = $this->family(); // sans email
        $ward->forceFill(['is_minor' => false])->save();
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->sever($ward->fresh(), $admin);

        $this->assertNull($ward->fresh()->guardian_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'guardianship_severed', 'target_id' => $ward->id,
        ]);
    }

    // L'écran admin ne doit pas proposer un geste que le service refuse (motif récurrent de la
    // branche). Le bouton disparaît en P1 et l'orientation vers l'autonomisation est visible.
    public function test_member_show_hides_sever_button_for_p1_ward(): void
    {
        [, $p1] = $this->family();          // P1 : sans email
        [, $p2] = $this->family('ado2@example.test'); // P2 : contrôle positif
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $p1])
            ->assertDontSeeHtml('wire:click="$set(\'confirmingSever\', true)"')
            ->assertSee('Inviter à activer son compte'); // le geste attendu en P1 est bien offert

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $p2])
            ->assertSeeHtml('wire:click="$set(\'confirmingSever\', true)"');

        // Majeur sans email : le bouton reste, c'est sa seule sortie.
        $p1->forceFill(['is_minor' => false])->save();
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $p1->fresh()])
            ->assertSeeHtml('wire:click="$set(\'confirmingSever\', true)"');
    }

    // Défense en profondeur : même en forçant l'appel Livewire (état périmé, second onglet),
    // le refus remonte en flash plutôt qu'en erreur 500.
    public function test_member_show_sever_on_p1_flashes_refusal(): void
    {
        [$parent, $p1] = $this->family();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $p1])
            ->call('severGuardianship')
            ->assertSee('compte propre', escape: false);

        $this->assertSame($parent->id, $p1->fresh()->guardian_id);
    }
}
