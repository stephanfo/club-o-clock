<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Livewire\SessionShow;
use App\Models\AperoFlag;
use App\Models\AuditLog;
use App\Models\Debrief;
use App\Models\NotificationOutbox;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\SessionDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Suppression définitive d'une séance (§4.7).
 *
 * L'annulation couvrait la séance qui n'a pas lieu ; rien ne couvrait la séance qui n'aurait jamais
 * dû exister — un doublon, une erreur de saisie. Elle restait affichée « annulée » indéfiniment, et
 * seul un accès SQL permettait de la retirer. Constaté en production le 2026-09-06 sur un doublon
 * de compétition.
 */
class SessionSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function seance(array $attrs = []): Session
    {
        return Session::create(array_merge([
            'kind' => 'training',
            'title' => 'Doublon à effacer',
            'start_at' => Carbon::now()->addDays(10)->setTime(13, 0),
            'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ], $attrs));
    }

    /** forceFill et non create() : `cancelled_at` n'est pas fillable — l'app pose le flag de même. */
    private function annulee(array $attrs = []): Session
    {
        $seance = $this->seance($attrs);
        $seance->forceFill([
            'cancelled_at' => Carbon::now(),
            'cancelled_by' => User::factory()->admin()->create()->id,
        ])->save();

        return $seance;
    }

    private function alerte(User $u, array $payload, array $attrs = []): NotificationOutbox
    {
        return NotificationOutbox::create(array_merge([
            'type' => 'event_created', 'channel' => 'push', 'payload' => $payload,
            'user_id' => $u->id, 'status' => 'sent', 'sent_at' => Carbon::now(),
        ], $attrs));
    }

    // ── Le geste ────────────────────────────────────────────────────────────────────────────────

    public function test_la_seance_annulee_est_effacee_de_la_base(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        app(SessionDeletionService::class)->delete($seance, $admin);

        $this->assertDatabaseMissing('sessions', ['id' => $seance->id]);
    }

    /** Ce que les cascades emportent — vérifié table par table, pas sur parole. */
    public function test_la_suppression_emporte_inscriptions_encadrement_et_aperos(): void
    {
        $seance = $this->annulee();
        $athlete = User::factory()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $reg = Registration::create([
            'session_id' => $seance->id, 'user_id' => $athlete->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);
        $seance->coaches()->attach($coach->id);
        AperoFlag::create([
            'session_id' => $seance->id, 'user_id' => $athlete->id,
            'registration_id' => $reg->id, 'flagged_at' => Carbon::now(),
        ]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $this->assertDatabaseMissing('registrations', ['id' => $reg->id]);
        $this->assertDatabaseMissing('session_coach', ['session_id' => $seance->id]);
        $this->assertDatabaseMissing('apero_flags', ['session_id' => $seance->id]);
    }

    // ── Les refus ───────────────────────────────────────────────────────────────────────────────

    public function test_une_seance_vivante_ne_se_supprime_pas(): void
    {
        $seance = $this->seance();
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('doit être annulée');
        app(SessionDeletionService::class)->delete($seance, $admin);
    }

    /** Contrôle positif apparié : la MÊME séance, une fois annulée, se supprime. */
    public function test_la_meme_seance_annulee_se_supprime(): void
    {
        $seance = $this->seance();
        $admin = User::factory()->admin()->create();

        $seance->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $admin->id])->save();
        app(SessionDeletionService::class)->delete($seance->fresh(), $admin);

        $this->assertDatabaseMissing('sessions', ['id' => $seance->id]);
    }

    /**
     * Un débrief est du texte écrit par un membre : la cascade l'effacerait sans que l'admin
     * l'ait lu. Même doctrine que les parcours (§4.20).
     */
    public function test_un_debrief_rattache_interdit_la_suppression(): void
    {
        $seance = $this->annulee(['kind' => 'competition']);
        $auteur = User::factory()->create();
        $admin = User::factory()->admin()->create();

        Debrief::create([
            'session_id' => $seance->id, 'author_id' => $auteur->id,
            'content_markdown' => 'Belle course malgré la pluie.',
        ]);

        try {
            app(SessionDeletionService::class)->delete($seance, $admin);
            $this->fail('Attendu : refus tant qu\'un débrief est rattaché.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('débrief', $e->getMessage());
        }

        $this->assertDatabaseHas('sessions', ['id' => $seance->id]);
    }

    public function test_seul_un_admin_peut_supprimer(): void
    {
        $seance = $this->annulee();

        $this->assertFalse(User::factory()->create()->can('delete', $seance));
        $this->assertFalse(User::factory()->coach()->create()->can('delete', $seance));
        $this->assertTrue(User::factory()->admin()->create()->can('delete', $seance));
    }

    /** La policy porte aussi l'état : un admin ne voit pas l'entrée sur une séance vivante. */
    public function test_la_policy_refuse_une_seance_non_annulee(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse($admin->can('delete', $this->seance()));
        $this->assertTrue($admin->can('delete', $this->annulee()));
    }

    // ── Ce qui survit ───────────────────────────────────────────────────────────────────────────

    /**
     * `audit_logs.session_id` est en ON DELETE SET NULL : une trace qui n'y tiendrait que par cette
     * colonne perdrait sa cible dans la seconde. target_type/target_id n'ont pas de clé étrangère.
     */
    public function test_la_trace_daudit_survit_avec_sa_cible_et_le_titre(): void
    {
        $seance = $this->annulee(['title' => 'SwimRun en double']);
        $admin = User::factory()->admin()->create();
        $id = $seance->id;

        app(SessionDeletionService::class)->delete($seance, $admin);

        $trace = AuditLog::where('action', 'delete_session')->firstOrFail();
        $this->assertSame('session', $trace->target_type);
        $this->assertSame($id, $trace->target_id, 'target_id n\'a pas de FK : il doit survivre.');
        $this->assertNull($trace->session_id, 'session_id est vidé par la cascade — d\'où le motif.');
        $this->assertStringContainsString('SwimRun en double', (string) $trace->motif);
    }

    /** Les journaux d'annulation antérieurs restent, détachés : l'historique n'est pas réécrit. */
    public function test_les_traces_anterieures_sont_conservees(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        $anterieure = AuditLog::create([
            'actor_id' => $admin->id, 'action' => 'cancel_session',
            'session_id' => $seance->id, 'created_at' => Carbon::now(),
        ]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $this->assertDatabaseHas('audit_logs', ['id' => $anterieure->id, 'action' => 'cancel_session']);
        $this->assertNull($anterieure->fresh()->session_id);
    }

    // ── Les alertes déjà envoyées ───────────────────────────────────────────────────────────────

    /**
     * Rattachées à la séance par un `session_id` DANS le JSON, sans clé étrangère : aucune cascade
     * ne les touche. On les rend autonomes avant de couper.
     */
    public function test_les_alertes_gardent_le_titre_et_perdent_le_lien(): void
    {
        $seance = $this->annulee(['title' => 'Sortie longue']);
        $destinataire = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $nue = $this->alerte($destinataire, ['session_id' => $seance->id]);
        $etrangere = $this->alerte($destinataire, ['session_id' => $this->annulee()->id]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $payload = $nue->fresh()->payload;
        $this->assertArrayNotHasKey('session_id', $payload, 'Sans session_id, Alerts ne pose plus de lien.');
        $this->assertSame('Sortie longue', $payload['session_title']);
        $this->assertNotNull($payload['session_start_at']);

        // Contrôle positif apparié : une alerte d'une AUTRE séance n'est pas touchée.
        $this->assertArrayHasKey('session_id', $etrangere->fresh()->payload);
    }

    /** Un titre déjà figé au payload fait foi : on ne réécrit pas ce que la notification disait. */
    public function test_un_titre_deja_present_nest_pas_ecrase(): void
    {
        $seance = $this->annulee(['title' => 'Titre corrigé depuis']);
        $admin = User::factory()->admin()->create();

        $alerte = $this->alerte(User::factory()->create(), [
            'session_id' => $seance->id, 'session_title' => 'Titre au moment de l\'envoi',
        ]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $this->assertSame('Titre au moment de l\'envoi', $alerte->fresh()->payload['session_title']);
    }

    /**
     * Le délien relit le payload puis le réécrit : il ne doit JAMAIS réécrire une version masquée.
     *
     * `redactedPayload()` est aujourd'hui une méthode explicite, donc `$ligne->payload` rend le
     * brut. Le jour où quelqu'un en ferait un accesseur, la suppression persisterait des « •••••• »
     * à la place des vraies valeurs — une perte silencieuse et définitive. Ce test tombe ce jour-là.
     */
    public function test_le_delien_ne_persiste_pas_un_payload_masque(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        $alerte = $this->alerte(User::factory()->create(), [
            'session_id' => $seance->id, 'token' => 'jeton-en-clair', 'autre' => 'valeur',
        ]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $payload = $alerte->fresh()->payload;
        $this->assertSame('jeton-en-clair', $payload['token'], 'Le délien a réécrit une valeur masquée.');
        $this->assertSame('valeur', $payload['autre']);
    }

    /** Contrôle positif : l'alerte reste lisible dans la cloche, séance disparue. */
    public function test_lecran_alertes_rend_encore_lalerte_orpheline(): void
    {
        $seance = $this->annulee(['title' => 'Compétition annulée puis effacée']);
        $destinataire = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->alerte($destinataire, ['session_id' => $seance->id]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        Livewire::actingAs($destinataire)->test(Alerts::class)
            ->assertSee('Compétition annulée puis effacée');
    }

    /**
     * Le créneau doit survivre dans la cloche, pas seulement le titre.
     *
     * Alerts::sousTitre ne repliait que le titre sur le payload : la séance disparue, sa ligne se
     * réduisait à « SwimRun St Nazaire » sans date, alors que le payload porte la réponse. Le repli
     * ne servait à rien tant que rien ne supprimait de séance — c'est cette PR qui le rend utile.
     */
    public function test_lalerte_orpheline_garde_son_creneau_et_pas_seulement_son_titre(): void
    {
        $seance = $this->annulee([
            'title' => 'Sortie longue',
            'start_at' => Carbon::parse('2026-09-26 13:00', 'UTC'),
        ]);
        $destinataire = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->alerte($destinataire, ['session_id' => $seance->id]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        Livewire::actingAs($destinataire)->test(Alerts::class)
            ->assertSee('Sortie longue')
            ->assertSee('26 sept.');
    }

    /**
     * Les deux compteurs du dialog disent deux choses différentes, et ne doivent pas se confondre.
     *
     * La cloche ne montre que les push ENVOYÉS de moins de 60 jours, or une annulation met en file
     * un push ET un email par destinataire. Un compteur unique annoncerait « déjà envoyées » des
     * lignes qui n'ont pas bougé.
     */
    public function test_le_decompte_separe_les_cloches_des_envois_en_attente(): void
    {
        $seance = $this->annulee();
        $u = User::factory()->create();

        $this->alerte($u, ['session_id' => $seance->id]);                                    // push envoyé → cloche
        $this->alerte($u, ['session_id' => $seance->id], ['channel' => 'email']);            // email envoyé → ni l'un ni l'autre
        $this->alerte($u, ['session_id' => $seance->id], ['status' => 'pending', 'sent_at' => null]);
        $this->alerte($u, ['session_id' => $seance->id], ['channel' => 'email', 'status' => 'pending', 'sent_at' => null]);
        // created_at n'est pas fillable : on le force après coup, comme le fait le vieillissement réel.
        $this->alerte($u, ['session_id' => $seance->id])
            ->forceFill(['created_at' => Carbon::now()->subDays(90)])->save();  // hors fenêtre de 60 j
        $this->alerte($u, ['session_id' => $this->annulee()->id]);                           // autre séance

        $this->assertSame(
            ['cloches' => 1, 'enAttente' => 2],
            SessionDeletionService::decompteEnvois($seance)
        );
    }

    /** Le tampon vaut aussi pour ce qui n'est pas encore parti : ces envois survivront à la séance. */
    public function test_les_envois_encore_en_file_sont_tamponnes_eux_aussi(): void
    {
        $seance = $this->annulee(['title' => 'Annulée puis effacée']);
        $admin = User::factory()->admin()->create();

        $enAttente = $this->alerte(User::factory()->create(), ['session_id' => $seance->id],
            ['type' => 'session_cancelled', 'channel' => 'email', 'status' => 'pending', 'sent_at' => null]);

        app(SessionDeletionService::class)->delete($seance, $admin);

        $payload = $enAttente->fresh()->payload;
        $this->assertSame('Annulée puis effacée', $payload['session_title']);
        $this->assertArrayNotHasKey('session_id', $payload);
        $this->assertSame('pending', $enAttente->fresh()->status, 'L\'envoi n\'est pas annulé : il doit partir.');
    }

    // ── L'écran ─────────────────────────────────────────────────────────────────────────────────

    public function test_la_fiche_supprime_apres_accuse_de_reception(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $seance])
            ->call('openDeleteConfirm')
            ->assertSet('deleteCheck', false)
            ->set('deleteCheck', true)
            ->call('delete')
            ->assertRedirect(route('planning'));

        $this->assertDatabaseMissing('sessions', ['id' => $seance->id]);
    }

    /** Le bouton grisé ne suffit pas : l'état vient du client, le refus est gardé côté serveur. */
    public function test_la_fiche_refuse_sans_accuse_de_reception(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $seance])
            ->call('openDeleteConfirm')
            ->set('deleteCheck', false)
            ->call('delete');

        $this->assertDatabaseHas('sessions', ['id' => $seance->id]);
    }

    /** L'accusé n'est jamais pré-coché : chaque ouverture le remet à zéro. */
    public function test_louverture_du_dialog_remet_laccuse_a_zero(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $seance])
            ->set('deleteCheck', true)
            ->call('openDeleteConfirm')
            ->assertSet('deleteCheck', false);
    }

    /** Contrôle positif apparié : l'entrée n'apparaît que là où le geste a un sens. */
    public function test_lentree_est_absente_sur_une_seance_vivante_et_presente_une_fois_annulee(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $this->seance()])
            ->assertDontSee('Supprimer définitivement');
        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $this->annulee()])
            ->assertSee('Supprimer définitivement');
    }

    /** Un coach voit la fiche annulée sans jamais y trouver l'entrée. */
    public function test_un_coach_ne_voit_pas_lentree(): void
    {
        Livewire::actingAs(User::factory()->coach()->create())
            ->test(SessionShow::class, ['session' => $this->annulee()])
            ->assertDontSee('Supprimer définitivement');
    }

    /**
     * Course entre deux admins : A ouvre le dialog, B restaure la séance, A confirme.
     *
     * Le geste ne doit pas effacer une séance redevenue vivante. Livewire ne transporte pas le
     * modèle dans le snapshot — il n'en garde que la clé et le relit à chaque requête —, donc la
     * policy voit l'état frais ; le service le revérifie de son côté. Ce test vérifie que cette
     * chaîne tient vraiment, plutôt que de le supposer.
     */
    public function test_une_restauration_concurrente_annule_la_suppression(): void
    {
        $seance = $this->annulee();
        $admin = User::factory()->admin()->create();

        $composant = Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $seance])
            ->call('openDeleteConfirm')
            ->set('deleteCheck', true);

        // Un autre admin réactive la séance, sans passer par ce composant.
        Session::whereKey($seance->id)->update(['cancelled_at' => null, 'cancelled_by' => null]);

        $composant->call('delete')->assertForbidden();

        $this->assertDatabaseHas('sessions', ['id' => $seance->id]);
    }

    /** Le blocage s'explique à l'écran plutôt que de se découvrir au clic. */
    public function test_la_fiche_explique_le_blocage_par_debrief(): void
    {
        $seance = $this->annulee(['kind' => 'competition']);
        Debrief::create([
            'session_id' => $seance->id, 'author_id' => User::factory()->create()->id,
            'content_markdown' => 'Récit de course.',
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(SessionShow::class, ['session' => $seance])
            ->assertSee('Suppression impossible')
            ->assertDontSee('Supprimer définitivement');
    }
}
