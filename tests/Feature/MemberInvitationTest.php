<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberCreate;
use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\MemberShow;
use App\Models\AuthIdentity;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Invitation d'activation d'un adhérent (PRD §4.1.3).
//
// Le trou que ces tests ferment : un compte créé par le bureau naissait avec email_verified_at nul
// et sans notification. Le lien magique exigeant un email vérifié, l'adhérent voyait le message
// neutre anti-énumération et ne recevait JAMAIS rien — aucun moyen d'entrer.
class MemberInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin']]);
    }

    /** @return array{0:User,1:User} l'admin et l'adhérent créé */
    private function membreCree(?string $email = 'nouveau@club.test'): array
    {
        $admin = $this->admin();
        $membre = app(MemberService::class)->create([
            'first_name' => 'Nouveau', 'last_name' => 'Membre',
            'dob' => '1990-05-04', 'email' => $email,
            'roles' => ['athlete'], 'surclassements' => [], 'qualifications' => [], 'guardian_id' => null,
        ], $admin);

        return [$admin, $membre];
    }

    // ── L'email saisi par le bureau vaut vérification (§4.1.3) ──

    public function test_member_created_with_email_is_verified(): void
    {
        [, $membre] = $this->membreCree();

        $this->assertNotNull($membre->fresh()->email_verified_at);
    }

    public function test_minor_without_email_stays_unverified(): void
    {
        // Mineur P1 : pas d'email, donc rien à vérifier et aucune invitation possible (§4.2).
        [, $membre] = $this->membreCree(null);

        $this->assertNull($membre->fresh()->email_verified_at);
    }

    public function test_verified_member_can_finally_request_a_magic_link(): void
    {
        // Le bout du fil : avant ce lot, cette demande ne créait AUCUN jeton.
        [, $membre] = $this->membreCree();

        $this->post('/magic-link', ['email' => $membre->email]);

        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    // ── Création unitaire : envoi immédiat ──

    public function test_creating_a_member_sends_the_invitation_immediately(): void
    {
        Livewire::actingAs($this->admin())->test(MemberCreate::class)
            ->set('first_name', 'Alice')->set('last_name', 'Martin')
            ->set('dob', '1992-03-14')->set('email', 'alice@club.test')
            ->call('create');

        $membre = User::where('email', 'alice@club.test')->firstOrFail();
        $ligne = NotificationOutbox::where('type', 'member_invitation')->where('user_id', $membre->id)->first();

        $this->assertNotNull($ligne);
        $this->assertSame('email', $ligne->channel);
        $this->assertSame('sent', $ligne->status, 'La création unitaire draine tout de suite.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member_invite_sent', 'target_id' => $membre->id]);
    }

    public function test_creating_a_member_without_invitation_when_unchecked(): void
    {
        Livewire::actingAs($this->admin())->test(MemberCreate::class)
            ->set('first_name', 'Bob')->set('last_name', 'Durand')
            ->set('dob', '1992-03-14')->set('email', 'bob@club.test')
            ->set('sendInvitation', false)
            ->call('create');

        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertDatabaseCount('invitation_tokens', 0);
    }

    // ── Renvoi depuis la fiche ──

    public function test_resending_invalidates_the_previous_link(): void
    {
        [$admin, $membre] = $this->membreCree();
        $ancien = app(InvitationService::class)->mint($membre);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])->call('sendInvitation');

        // La route d'activation est sous `guest` : sans cette déconnexion, on mesurerait la
        // redirection du middleware et non le sort du jeton.
        auth()->logout();

        // L'ancien lien ne doit plus ouvrir : un lien qu'on croit remplacé ne peut pas rester honoré.
        $this->get("/invitation/{$ancien}")->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('invitation_tokens', 1);
    }

    public function test_invitation_refused_for_member_without_email(): void
    {
        [$admin, $membre] = $this->membreCree(null);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->call('sendInvitation');

        $this->assertDatabaseCount('invitation_tokens', 0);
    }

    public function test_invitation_refused_for_inactive_member(): void
    {
        // is_active=false couvre la demande de suppression en cours (§4.3).
        [$admin, $membre] = $this->membreCree();
        $membre->update(['is_active' => false]);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->call('sendInvitation');

        $this->assertDatabaseCount('invitation_tokens', 0);
    }

    public function test_non_admin_cannot_reach_the_member_sheet(): void
    {
        [, $membre] = $this->membreCree();

        foreach ([['athlete'], ['coach']] as $roles) {
            Livewire::actingAs(User::factory()->create(['roles' => $roles]))
                ->test(MemberShow::class, ['user' => $membre])
                ->assertForbidden();
        }
    }

    public function test_resending_is_throttled(): void
    {
        [$admin, $membre] = $this->membreCree();

        $composant = Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre]);
        for ($i = 0; $i < 4; $i++) {
            $composant->call('sendInvitation');
        }

        // 3 envois autorisés par heure : le 4e est refusé (spam vers la boîte de l'adhérent).
        $this->assertSame(3, NotificationOutbox::where('type', 'member_invitation')->count());
    }

    // ── Invitations en attente (rattrapage de masse) ──

    public function test_awaiting_list_excludes_activated_and_invited_accounts(): void
    {
        $service = app(InvitationService::class);

        [, $jamaisInvite] = $this->membreCree('jamais@club.test');

        $avecMdp = User::factory()->create(['email' => 'mdp@club.test', 'password' => 'password']);

        $avecGoogle = User::factory()->create(['email' => 'g@club.test', 'password' => null]);
        AuthIdentity::create([
            'user_id' => $avecGoogle->id, 'provider' => 'google', 'provider_uid' => 'uid-1',
            'email_at_link' => 'g@club.test', 'linked_at' => Carbon::now(),
        ]);

        $dejaActive = User::factory()->create(['email' => 'active@club.test', 'password' => null]);
        InvitationToken::create([
            'user_id' => $dejaActive->id, 'token_hash' => hash('sha256', 'x'),
            'expires_at' => Carbon::now()->addDay(), 'consumed_at' => Carbon::now(),
        ]);

        $invitationVivante = User::factory()->create(['email' => 'vivante@club.test', 'password' => null]);
        $service->mint($invitationVivante);

        $ids = $service->awaitingInvitation()->pluck('id');

        $this->assertTrue($ids->contains($jamaisInvite->id));
        foreach ([$avecMdp, $avecGoogle, $dejaActive, $invitationVivante] as $exclu) {
            $this->assertFalse($ids->contains($exclu->id), "Compte {$exclu->email} ne doit pas être re-sollicité.");
        }
    }

    public function test_bulk_invitation_queues_without_draining_and_is_idempotent(): void
    {
        $this->membreCree('un@club.test');
        $this->membreCree('deux@club.test');

        $composant = Livewire::actingAs($this->admin())->test(MemberList::class);
        $composant->call('sendPendingInvitations');

        $lignes = NotificationOutbox::where('type', 'member_invitation')->get();
        $this->assertCount(2, $lignes);
        // Mise en FILE : 200 envois SMTP synchrones tiendraient dans aucune requête sur mutualisé.
        $this->assertTrue($lignes->every(fn ($l) => $l->status === 'pending'));

        // Second clic : plus personne en attente (chacun a une invitation vivante).
        $composant->call('sendPendingInvitations');
        $this->assertSame(2, NotificationOutbox::where('type', 'member_invitation')->count());
    }

    public function test_non_admin_cannot_reach_the_member_list(): void
    {
        Livewire::actingAs(User::factory()->create(['roles' => ['athlete']]))
            ->test(MemberList::class)
            ->assertForbidden();
    }

    // ── Le jeton consommé est le marqueur d'activation ──

    public function test_prune_keeps_consumed_tokens_but_drops_expired_ones(): void
    {
        [, $membre] = $this->membreCree();

        InvitationToken::create([
            'user_id' => $membre->id, 'token_hash' => hash('sha256', 'consomme'),
            'expires_at' => Carbon::now()->subDay(), 'consumed_at' => Carbon::now()->subDay(),
        ]);
        InvitationToken::create([
            'user_id' => $membre->id, 'token_hash' => hash('sha256', 'perime'),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->artisan('model:prune', ['--model' => [InvitationToken::class]]);

        $this->assertDatabaseHas('invitation_tokens', ['token_hash' => hash('sha256', 'consomme')]);
        $this->assertDatabaseMissing('invitation_tokens', ['token_hash' => hash('sha256', 'perime')]);
    }
}
