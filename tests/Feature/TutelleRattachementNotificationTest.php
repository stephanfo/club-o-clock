<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use App\Services\GuardianshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// guardianship_linked (#29) : le garant entrant acquiert l'accès aux données d'un mineur et le droit
// d'agir en son nom ; il l'apprenait en le découvrant à l'écran. Émis par le rattachement (link) et
// par le changement de garant (relink), au garant entrant et au pupille qui a un compte propre (P2).
class TutelleRattachementNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function mineur(?string $email = null, ?User $garant = null): User
    {
        return User::factory()->create([
            'first_name' => 'Léo',
            'dob' => Carbon::now()->subYears(12)->toDateString(),
            'email' => $email,
            'password' => null,
            'guardian_id' => $garant?->id,
            'guardianship_linked_at' => $garant ? Carbon::now() : null,
        ]);
    }

    private function lignes(User $destinataire)
    {
        return NotificationOutbox::where('type', NotificationType::GuardianshipLinked->value)
            ->where('user_id', $destinataire->id)->get();
    }

    public function test_le_changement_de_garant_previent_le_garant_entrant_et_le_pupille_p2(): void
    {
        $sortant = User::factory()->create();
        $pupille = $this->mineur('leo@example.test', $sortant);
        $entrant = User::factory()->create(['first_name' => 'Yanis', 'last_name' => 'Martin']);

        app(GuardianshipService::class)->relink($pupille, $entrant, User::factory()->admin()->create());

        $this->assertNotEmpty($this->lignes($entrant));
        $this->assertNotEmpty($this->lignes($pupille));
        $this->assertEmpty($this->lignes($sortant), 'Le garant sortant reçoit la rupture, pas le rattachement.');
    }

    /** Minimisation (§4.19) : le nom du garant a servi au corps, il ne dort pas dans la file. */
    public function test_le_nom_du_garant_est_purge_a_lenvoi(): void
    {
        $pupille = $this->mineur('leo@example.test');
        $garant = User::factory()->create(['first_name' => 'Yanis', 'last_name' => 'Martin']);
        $this->app->instance(FakeChannel::class, new FakeChannel);
        config([
            'club.notifications.channels.push' => FakeChannel::class,
            'club.notifications.channels.email' => FakeChannel::class,
        ]);

        app(GuardianshipService::class)->link($pupille, $garant, User::factory()->admin()->create());
        $ligne = $this->lignes($pupille)->first();
        $this->assertSame('Yanis Martin', $ligne->payload['guardian_name']); // contrôle positif : tant qu'elle attend

        app(OutboxDrainer::class)->drainNow(NotificationOutbox::whereKey($ligne->id)->get());

        $ligne->refresh();
        $this->assertSame('sent', $ligne->status);
        $this->assertArrayNotHasKey('guardian_name', $ligne->payload);
        $this->assertStringNotContainsString('Martin', json_encode($ligne->payload));
    }

    public function test_le_rattachement_dun_orphelin_previent_aussi(): void
    {
        $pupille = $this->mineur('leo@example.test');
        $garant = User::factory()->create();

        app(GuardianshipService::class)->link($pupille, $garant, User::factory()->admin()->create());

        $this->assertNotEmpty($this->lignes($garant));
        $this->assertNotEmpty($this->lignes($pupille));
    }

    /** P1 : l'enfant n'a aucun canal propre, seul son garant est prévenu. */
    public function test_un_pupille_p1_ne_recoit_rien(): void
    {
        $pupille = $this->mineur();
        $garant = User::factory()->create();

        app(GuardianshipService::class)->link($pupille, $garant, User::factory()->admin()->create());

        $this->assertNotEmpty($this->lignes($garant));
        $this->assertEmpty($this->lignes($pupille));
    }

    /** Un rattachement refusé ne notifie personne. */
    public function test_un_rattachement_refuse_ne_previent_personne(): void
    {
        $pupille = $this->mineur('leo@example.test', User::factory()->create());
        $garant = User::factory()->create();

        try {
            app(GuardianshipService::class)->link($pupille, $garant, User::factory()->admin()->create());
            $this->fail('Le rattachement d\'un pupille qui a déjà un garant doit être refusé.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, NotificationOutbox::where('type', NotificationType::GuardianshipLinked->value)->count());
    }

    public function test_le_garant_lit_le_prenom_de_lenfant_et_va_sur_mes_enfants(): void
    {
        $pupille = $this->mineur();
        $garant = User::factory()->create();

        app(GuardianshipService::class)->link($pupille, $garant, User::factory()->admin()->create());

        $rendu = app(NotificationRenderer::class)->render($this->lignes($garant)->first());
        $this->assertSame('Léo · Nouveau parent garant', $rendu['title']);
        $this->assertSame('Tu es désormais parent garant de Léo', $rendu['body']);
        $this->assertStringEndsWith('/enfants', $rendu['url']);
    }

    public function test_le_pupille_lit_le_nom_de_son_nouveau_garant_et_va_sur_son_profil(): void
    {
        $pupille = $this->mineur('leo@example.test', User::factory()->create());
        $entrant = User::factory()->create(['first_name' => 'Yanis', 'last_name' => 'Martin']);

        app(GuardianshipService::class)->relink($pupille, $entrant, User::factory()->admin()->create());

        $rendu = app(NotificationRenderer::class)->render($this->lignes($pupille)->first());
        $this->assertSame('Nouveau parent garant', $rendu['title']);
        $this->assertSame('Ton parent garant est désormais '.$entrant->fullName(), $rendu['body']);
        $this->assertStringEndsWith('/profil', $rendu['url']);
    }

    // ── Fiche adhérent : le rattachement notifie des tiers, donc confirmation avec accusé ──

    public function test_la_fiche_rattache_apres_accuse_de_reception_et_nomme_les_prevenus(): void
    {
        $pupille = $this->mineur('leo@example.test');
        $garant = User::factory()->create(['first_name' => 'Yanis']);

        Livewire::actingAs(User::factory()->admin()->create())->test(MemberShow::class, ['user' => $pupille])
            ->set('linkGuardianId', $garant->id)
            ->call('openLink', 'guardian')
            ->assertSet('linkDialog', 'guardian')
            ->assertSee('Je comprends que Yanis et Léo seront prévenu·e·s du rattachement.')
            ->set('linkCheck', true)
            ->call('linkGuardian')
            ->assertHasNoErrors()
            ->assertSet('linkDialog', null);

        $this->assertSame($garant->id, $pupille->fresh()->guardian_id);
    }

    /** Le bouton grisé ne suffit pas : l'état vient du client, le refus est gardé côté serveur. */
    public function test_la_fiche_refuse_le_rattachement_sans_accuse_de_reception(): void
    {
        $pupille = $this->mineur();
        $garant = User::factory()->create();
        $admin = User::factory()->admin()->create();

        // Sans dialog ouvert (appel direct) puis dialog ouvert mais case décochée : ni lien ni envoi.
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pupille])
            ->set('linkGuardianId', $garant->id)
            ->set('linkCheck', true)
            ->call('linkGuardian')
            ->call('openLink', 'guardian')
            ->call('linkGuardian');

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $garant])
            ->set('addingWard', true)
            ->set('linkWardId', $pupille->id)
            ->set('linkCheck', true)
            ->call('linkWard');

        $this->assertNull($pupille->fresh()->guardian_id);
        $this->assertSame(0, NotificationOutbox::where('type', NotificationType::GuardianshipLinked->value)->count());
    }

    /** L'accusé n'est jamais pré-coché, et le dialog ne s'ouvre pas sans choix. */
    public function test_louverture_remet_laccuse_a_zero_et_exige_un_choix(): void
    {
        $pupille = $this->mineur();
        $garant = User::factory()->create();

        Livewire::actingAs(User::factory()->admin()->create())->test(MemberShow::class, ['user' => $pupille])
            ->call('openLink', 'guardian')
            ->assertHasErrors('linkGuardianId')
            ->assertSet('linkDialog', null)
            ->set('linkGuardianId', $garant->id)
            ->set('linkCheck', true)
            ->call('openLink', 'guardian')
            ->assertSet('linkCheck', false)
            ->assertSet('linkDialog', 'guardian')
            ->assertSee('Je comprends que '.$garant->first_name.' sera prévenu·e du rattachement.');
    }
}
