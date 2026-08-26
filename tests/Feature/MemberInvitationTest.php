<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberCreate;
use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\MemberShow;
use App\Models\AuthIdentity;
use App\Models\ClubSettings;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Services\InvitationService;
use App\Services\MemberService;
use App\Support\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
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

    public function test_bulk_invitation_caps_in_the_query_not_in_memory(): void
    {
        // Le plafond doit borner la REQUÊTE : appliqué à une collection déjà hydratée, il chargeait
        // tous les adhérents en attente en mémoire pour n'en garder qu'une poignée.
        foreach (['a', 'b', 'c'] as $prenom) {
            $this->membreCree("{$prenom}@club.test");
        }

        $this->assertCount(2, app(InvitationService::class)->awaitingInvitation(2));
        $this->assertCount(3, app(InvitationService::class)->awaitingInvitation());
    }

    // ── La modale annonce ce que le clic fait vraiment ──

    /** Insère en masse des comptes « jamais entrés » — 800 factories seraient trop lentes ici. */
    private function comptesEnAttente(int $nombre): void
    {
        $lignes = [];
        for ($i = 0; $i < $nombre; $i++) {
            $lignes[] = [
                'first_name' => 'Lot', 'last_name' => 'Numero'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'email' => "lot{$i}@club.test", 'email_verified_at' => now(),
                'roles' => json_encode(['athlete']), 'is_active' => true, 'is_minor' => false,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        User::insert($lignes);
    }

    public function test_the_confirmation_modal_announces_the_real_batch_not_the_backlog(): void
    {
        // La modale affichait `$awaiting`, le total en attente, alors que le clic est plafonné à
        // BULK_INVITE_CAP : l'admin d'un gros import lisait « 800 invitations mises en file », en
        // envoyait 500, et les 300 restants étaient omis sans un mot.
        $this->comptesEnAttente(MemberList::BULK_INVITE_CAP + 300);

        Livewire::actingAs($this->admin())->test(MemberList::class)
            ->call('confirmBulkInvite')
            ->assertSee('<b>'.MemberList::BULK_INVITE_CAP.'</b> invitations mises en file', escape: false)
            ->assertDontSee('<b>'.(MemberList::BULK_INVITE_CAP + 300).'</b> invitation', escape: false)
            // Le reliquat est dit, sinon l'admin croit l'action terminée alors qu'elle est à relancer.
            ->assertSee('<b>300</b> compte', escape: false);
    }

    public function test_the_confirmation_modal_says_nothing_about_a_leftover_when_there_is_none(): void
    {
        // Contrôle positif apparié : sous le plafond, le nombre annoncé EST l'arriéré et la ligne
        // « Reste » ne doit pas apparaître.
        $this->comptesEnAttente(12);

        Livewire::actingAs($this->admin())->test(MemberList::class)
            ->call('confirmBulkInvite')
            ->assertSee('<b>12</b> invitations mises en file', escape: false)
            ->assertDontSee('Envoi limité à');
    }

    // ── L'invitation porte un accès au compte, pas une notification (§4.15.1) ──
    //
    // Renversement assumé d'un comportement antérieur : l'interrupteur de canal (§4.17) et la pause
    // (§4.15.4) refusaient l'invitation. Un club en push seul ne pouvait alors faire entrer
    // personne — pas d'activation, donc pas de PWA, donc jamais de push — alors que le lien magique
    // partait déjà. Le blocage était inopérant autant qu'incohérent.

    public function test_invitation_is_sent_even_when_the_club_email_channel_is_off(): void
    {
        [$admin, $membre] = $this->membreCree();
        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->call('sendInvitation')
            ->assertDontSee('canal email du club est coupé');

        // Le jeton est frappé ET la ligne existe : l'invitation part réellement.
        $this->assertDatabaseCount('invitation_tokens', 1);
        $this->assertSame(1, NotificationOutbox::where('type', 'member_invitation')->count());
    }

    public function test_invitation_is_sent_even_when_the_member_paused_notifications(): void
    {
        [$admin, $membre] = $this->membreCree();
        $membre->notificationPreferences()->create(['paused' => true, 'matrix' => []]);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->call('sendInvitation')
            ->assertDontSee('mis ses notifications en pause');

        $this->assertDatabaseCount('invitation_tokens', 1);
        $this->assertSame(1, NotificationOutbox::where('type', 'member_invitation')->count());
    }

    public function test_an_ordinary_notification_is_still_blocked_by_the_closed_channel(): void
    {
        // Contrôle positif apparié : l'exemption vise les invitations SEULES. Sans ce test, une
        // exemption trop large (tous les types) passerait inaperçue.
        $membre = User::factory()->create();
        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        app(NotificationDispatcher::class)->dispatch(NotificationType::SessionCancelled, $membre);

        $this->assertSame(0, NotificationOutbox::where('channel', 'email')->count());
    }

    public function test_a_sent_invitation_leaves_the_awaiting_list(): void
    {
        // Miroir du test d'origine : le rattrapage ne doit PLUS voir ce compte, puisque le jeton
        // vivant est désormais légitime — l'invitation est bien partie.
        [$admin, $membre] = $this->membreCree();
        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        app(InvitationService::class)->sendToMember($membre, $admin);

        $this->assertFalse(app(InvitationService::class)->awaitingInvitation()->contains('id', $membre->id));
    }

    public function test_bulk_invitation_queues_lines_when_the_email_channel_is_off(): void
    {
        $this->membreCree('un@club.test');
        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        Livewire::actingAs($this->admin())->test(MemberList::class)
            ->call('sendPendingInvitations')
            ->assertDontSee('Aucune invitation');

        $this->assertSame(1, NotificationOutbox::where('type', 'member_invitation')->count());
    }

    public function test_creating_a_member_sends_the_invitation_when_the_email_channel_is_off(): void
    {
        ClubSettings::current()->update(['notif_email_enabled' => false]);
        ClubSettings::flushCache();

        Livewire::actingAs($this->admin())->test(MemberCreate::class)
            ->set('first_name', 'Chloé')->set('last_name', 'Bernard')
            ->set('dob', '1992-03-14')->set('email', 'chloe@club.test')
            ->call('create');

        $membre = User::where('email', 'chloe@club.test')->firstOrFail();
        $this->assertDatabaseCount('invitation_tokens', 1);
        $this->assertFalse(app(InvitationService::class)->awaitingInvitation()->contains('id', $membre->id));
    }

    // ── Les gardes métier, elles, tiennent toujours ──

    public function test_invitation_is_still_refused_without_an_email(): void
    {
        [$admin, $membre] = $this->membreCree(null);

        try {
            app(InvitationService::class)->sendToMember($membre, $admin);
            $this->fail('L\'invitation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $this->assertDatabaseCount('invitation_tokens', 0);
    }

    public function test_invitation_is_still_refused_on_an_inactive_account(): void
    {
        [$admin, $membre] = $this->membreCree();
        $membre->update(['is_active' => false]);

        try {
            app(InvitationService::class)->sendToMember($membre->fresh(), $admin);
            $this->fail('L\'invitation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('actif', $e->getMessage());
        }

        $this->assertDatabaseCount('invitation_tokens', 0);
    }

    // ── Le lien magique seul est une activation (§4.1.1) ──

    public function test_a_member_who_only_ever_used_a_magic_link_counts_as_activated(): void
    {
        // Ni mot de passe, ni OAuth, ni invitation consommée : les trois marqueurs historiques
        // rataient le parcours passwordless, et ce compte se faisait relancer à vie.
        [$admin, $membre] = $this->membreCree();

        $emis = MagicLink::issue($membre->email);
        $this->get('/magic-link/'.str($emis['url'])->afterLast('/')->toString())->assertRedirect();

        $membre->refresh();
        $this->assertNotNull($membre->last_login_at);
        $this->assertFalse(app(InvitationService::class)->awaitingInvitation()->contains('id', $membre->id));

        auth()->logout();
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->assertSee('Compte activé')
            ->assertDontSee('Jamais invité');
    }

    public function test_a_member_who_never_logged_in_is_still_flagged(): void
    {
        // Contrôle positif apparié : sans connexion, le bandeau d'alerte doit bien apparaître.
        [$admin, $membre] = $this->membreCree();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->assertSee('Jamais invité');
        $this->assertTrue(app(InvitationService::class)->awaitingInvitation()->contains('id', $membre->id));
    }

    public function test_password_login_also_stamps_the_last_login(): void
    {
        // Le marqueur est branché sur l'événement du garde : il vaut pour TOUS les moyens.
        $membre = User::factory()->create(['email' => 'mdp@club.test', 'password' => 'password-de-test']);

        $this->post('/login', ['email' => 'mdp@club.test', 'password' => 'password-de-test']);

        $this->assertNotNull($membre->fresh()->last_login_at);
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
