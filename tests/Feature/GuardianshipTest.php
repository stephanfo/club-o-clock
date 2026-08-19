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

    public function test_sever_p1_ward_without_email_gets_push_only(): void
    {
        [$parent, $child] = $this->family(); // P1 : enfant sans email
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->sever($child, $admin);

        // L'enfant sans adresse n'a que le push ; le garant a push + email.
        $this->assertSame(['push'], NotificationOutbox::where('user_id', $child->id)
            ->pluck('channel')->all());
        $this->assertSame(2, NotificationOutbox::where('user_id', $parent->id)->count());
    }
}
