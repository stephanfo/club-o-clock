<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use App\Services\GuardianshipService;
use App\Services\SessionNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Contexte porté par une notification (PRD §4.15.2, §4.15.5) : QUI elle concerne quand le sujet
// n'est pas le destinataire (parent garant), et QUELLE séance.
//
// Le défaut corrigé : un parent garant lui-même athlète recevait « une séance à laquelle tu es
// inscrit·e est annulée », sans nom d'enfant ni nom de séance, et atterrissait sur une fiche qui
// parlait de lui. Deux notifications d'enfants différents étaient rigoureusement indiscernables.
class NotificationContexteSujetTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    private function renderer(): NotificationRenderer
    {
        return app(NotificationRenderer::class);
    }

    /** Famille P1 : l'enfant n'a pas de compte propre, le garant reçoit tout (§4.15.5). */
    private function famille(): array
    {
        $garant = User::factory()->create(['first_name' => 'Claire']);
        $enfant = User::factory()->minorP1()->create([
            'guardian_id' => $garant->id,
            'first_name' => 'Hugo',
        ]);

        return [$garant, $enfant];
    }

    private function seance(string $titre = 'Natation jeunes'): Session
    {
        return Session::create([
            'kind' => 'training',
            'title' => $titre,
            // 18:00 heure de Paris — le stockage est en UTC, le rendu doit reposer l'heure du club.
            'start_at' => Carbon::parse('2026-09-05 18:00', 'Europe/Paris'),
            'duration_min' => 60,
            'capacity' => 10,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    private function ligne(NotificationType $type, User $destinataire, array $payload): NotificationOutbox
    {
        return NotificationOutbox::create([
            'type' => $type->value,
            'channel' => 'push',
            'payload' => $payload,
            'user_id' => $destinataire->id,
            'status' => 'pending',
            'available_at' => Carbon::now(),
        ]);
    }

    // ── Émission : le sujet rejoint le payload quand il n'est pas le destinataire ──

    public function test_le_sujet_est_pose_quand_la_notif_part_au_garant(): void
    {
        [$garant, $enfant] = $this->famille();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $enfant);

        $ligne = NotificationOutbox::where('user_id', $garant->id)->firstOrFail();
        $this->assertSame($enfant->id, $ligne->payload['subject_id']);
        $this->assertSame('Hugo', $ligne->payload['subject_first_name']);
    }

    /** Le refus apparié : sans sujet distinct, aucune clé — sinon toute notif serait préfixée. */
    public function test_aucun_sujet_quand_le_destinataire_est_le_sujet(): void
    {
        $adulte = User::factory()->create();

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $adulte);

        $ligne = NotificationOutbox::where('user_id', $adulte->id)->firstOrFail();
        $this->assertArrayNotHasKey('subject_id', $ligne->payload);
        $this->assertArrayNotHasKey('subject_first_name', $ligne->payload);
    }

    /** P2 : l'enfant reçoit SA notification sans préfixe, le garant la sienne avec. */
    public function test_en_p2_seule_la_ligne_du_garant_porte_le_sujet(): void
    {
        $garant = User::factory()->create();
        $enfant = User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $garant->id,
            'email' => 'hugo@club.test',
            'first_name' => 'Hugo',
        ]);

        $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $enfant);

        $this->assertArrayNotHasKey(
            'subject_id',
            NotificationOutbox::where('user_id', $enfant->id)->firstOrFail()->payload
        );
        $this->assertSame(
            $enfant->id,
            NotificationOutbox::where('user_id', $garant->id)->firstOrFail()->payload['subject_id']
        );
    }

    // ── Purge : le prénom ne survit pas à l'envoi ──

    public function test_le_prenom_est_purge_a_l_envoi_mais_pas_l_identifiant(): void
    {
        [$garant, $enfant] = $this->famille();
        $this->app->instance(FakeChannel::class, new FakeChannel);
        config([
            'club.notifications.channels.push' => FakeChannel::class,
            'club.notifications.channels.email' => FakeChannel::class,
        ]);

        $lignes = $this->dispatcher()->dispatch(NotificationType::SessionCancelled, $enfant);
        app(OutboxDrainer::class)->drainNow($lignes);

        $envoyee = NotificationOutbox::where('user_id', $garant->id)->firstOrFail();
        $this->assertSame('sent', $envoyee->status);
        $this->assertArrayNotHasKey('subject_first_name', $envoyee->payload);
        // subject_id reste : c'est lui que la page Alertes re-résout en prénom.
        $this->assertSame($enfant->id, $envoyee->payload['subject_id']);
    }

    /** Contrôle positif apparié : une ligne en échec garde de quoi se rejouer entièrement. */
    public function test_une_ligne_en_echec_garde_le_prenom_pour_le_rejeu(): void
    {
        [$garant, $enfant] = $this->famille();
        $ligne = $this->ligne(NotificationType::SessionCancelled, $garant, [
            'session_id' => 1, 'subject_id' => $enfant->id, 'subject_first_name' => 'Hugo',
        ]);
        $ligne->update(['status' => 'failed']);

        $this->assertSame('Hugo', $ligne->fresh()->payload['subject_first_name']);
    }

    // ── Rendu : titre, corps, lien ──

    public function test_le_titre_porte_le_prenom_de_l_enfant(): void
    {
        [$garant, $enfant] = $this->famille();
        $ligne = $this->ligne(NotificationType::SessionCancelled, $garant, [
            'session_id' => 4, 'subject_id' => $enfant->id, 'subject_first_name' => 'Hugo',
        ]);

        $this->assertSame('Hugo · Annulation de séance', $this->renderer()->render($ligne)['title']);
    }

    public function test_le_titre_reste_nu_pour_sa_propre_notification(): void
    {
        $adulte = User::factory()->create();
        $ligne = $this->ligne(NotificationType::SessionCancelled, $adulte, ['session_id' => 4]);

        $this->assertSame('Annulation de séance', $this->renderer()->render($ligne)['title']);
    }

    public function test_le_corps_dit_quelle_seance_a_l_heure_du_club(): void
    {
        $seance = $this->seance();
        $ligne = $this->ligne(
            NotificationType::SessionModified,
            User::factory()->create(),
            $seance->payloadNotification()
        );

        // 18:00 Europe/Paris, stockées en UTC (16:00) : le rendu doit reposer le fuseau du club.
        $this->assertSame('Natation jeunes · sam. 5 sept. · 18:00', $this->renderer()->render($ligne)['body']);
    }

    public function test_le_lien_amene_le_parent_sur_la_fiche_avec_l_enfant_pour_sujet(): void
    {
        [$garant, $enfant] = $this->famille();
        $ligne = $this->ligne(NotificationType::SessionCancelled, $garant, [
            'session_id' => 42, 'subject_id' => $enfant->id, 'subject_first_name' => 'Hugo',
        ]);

        $this->assertStringContainsString('/seances/42', $this->renderer()->render($ligne)['url']);
        $this->assertStringContainsString('as='.$enfant->id, $this->renderer()->render($ligne)['url']);
    }

    public function test_le_lien_reste_nu_pour_sa_propre_notification(): void
    {
        $adulte = User::factory()->create();
        $ligne = $this->ligne(NotificationType::SessionCancelled, $adulte, ['session_id' => 42]);

        $this->assertStringEndsWith('/seances/42', $this->renderer()->render($ligne)['url']);
    }

    /**
     * Compatibilité des lignes DÉJÀ EN FILE au déploiement, et invariant permanent : une invitation
     * n'a jamais de séance, une ligne `failed` se rejoue longtemps après. Chaque enrichissement est
     * conditionné à sa clé et retombe sur le libellé du type.
     */
    public function test_une_ligne_sans_contexte_se_rend_comme_avant(): void
    {
        $ligne = $this->ligne(NotificationType::SessionCancelled, User::factory()->create(), ['session_id' => 9]);

        $rendu = $this->renderer()->render($ligne);

        $this->assertSame(NotificationType::SessionCancelled->label(), $rendu['title']);
        $this->assertSame(NotificationType::SessionCancelled->description(), $rendu['body']);
        $this->assertStringEndsWith('/seances/9', $rendu['url']);
    }

    /** Un horodatage illisible ne doit pas faire échouer un envoi : on retombe sur le titre seul. */
    public function test_une_date_illisible_ne_casse_pas_le_rendu(): void
    {
        $ligne = $this->ligne(NotificationType::SessionModified, User::factory()->create(), [
            'session_id' => 9, 'session_title' => 'Sortie longue', 'session_start_at' => 'pas-une-date',
        ]);

        $this->assertSame('Sortie longue', $this->renderer()->render($ligne)['body']);
    }

    // ── Types adressés au garant : réactivation et rupture de tutelle ──

    public function test_la_reactivation_lue_par_le_garant_mene_a_mes_enfants(): void
    {
        [$garant, $enfant] = $this->famille();
        $ligne = $this->ligne(NotificationType::AthleteReactivated, $garant, [
            'user_id' => $enfant->id, 'subject_id' => $enfant->id, 'subject_first_name' => 'Hugo',
        ]);

        $rendu = $this->renderer()->render($ligne);
        $this->assertStringEndsWith('/enfants', $rendu['url']);
        $this->assertSame('Hugo · Compte réactivé', $rendu['title']);
    }

    public function test_la_reactivation_lue_par_l_interesse_mene_au_dashboard(): void
    {
        $membre = User::factory()->create();
        $ligne = $this->ligne(NotificationType::AthleteReactivated, $membre, ['user_id' => $membre->id]);

        $this->assertStringEndsWith('/dashboard', $this->renderer()->render($ligne)['url']);
    }

    public function test_la_rupture_de_tutelle_nomme_l_enfant_au_garant(): void
    {
        $garant = User::factory()->create();
        $enfant = User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $garant->id,
            'email' => 'hugo@club.test',
            'first_name' => 'Hugo',
            'guardianship_linked_at' => Carbon::now(),
        ]);

        app(GuardianshipService::class)->sever($enfant, User::factory()->admin()->create());

        $ligneGarant = NotificationOutbox::where('user_id', $garant->id)
            ->where('type', NotificationType::GuardianshipSevered->value)
            ->firstOrFail();

        $rendu = $this->renderer()->render($ligneGarant);
        $this->assertSame('Hugo · Lien de tutelle rompu', $rendu['title']);
        // Surtout pas « Mes enfants » : le lien vient d'être coupé, l'enfant n'y figure plus.
        $this->assertStringEndsWith('/profil', $rendu['url']);

        // Contrôle positif apparié : l'enfant, lui, reçoit la même notif sans préfixe.
        $ligneEnfant = NotificationOutbox::where('user_id', $enfant->id)
            ->where('type', NotificationType::GuardianshipSevered->value)
            ->firstOrFail();
        $this->assertSame('Lien de tutelle rompu', $this->renderer()->render($ligneEnfant)['title']);
    }

    public function test_la_reactivation_lue_par_le_garant_ne_tutoie_pas_le_parent(): void
    {
        [$garant, $enfant] = $this->famille();
        $ligne = $this->ligne(NotificationType::AthleteReactivated, $garant, [
            'user_id' => $enfant->id, 'subject_id' => $enfant->id, 'subject_first_name' => 'Hugo',
        ]);

        // « Ton accès athlète est réactivé » désignait le parent, qui lit, et non l'enfant, dont
        // l'accès a bougé. C'est le seul type sans séance qui remonte au garant.
        $this->assertSame("L'accès athlète de Hugo est réactivé", $this->renderer()->render($ligne)['body']);
    }

    public function test_la_reactivation_lue_par_l_interesse_garde_son_tutoiement(): void
    {
        $membre = User::factory()->create();
        $ligne = $this->ligne(NotificationType::AthleteReactivated, $membre, ['user_id' => $membre->id]);

        // Contrôle apparié au test précédent : sans sujet distinct, la description du type convient.
        $this->assertSame('Ton accès athlète est réactivé', $this->renderer()->render($ligne)['body']);
    }

    public function test_la_reactivation_sans_prenom_reste_juste(): void
    {
        [$garant, $enfant] = $this->famille();
        // Ligne `failed` rejouée après purge du prénom : vague, mais jamais adressée au mauvais.
        $ligne = $this->ligne(NotificationType::AthleteReactivated, $garant, [
            'user_id' => $enfant->id, 'subject_id' => $enfant->id,
        ]);

        $this->assertSame("L'accès athlète de ton enfant est réactivé", $this->renderer()->render($ligne)['body']);
    }

    // ── Récapitulatif de série (§4.8) : volume et plage déjà au payload ──

    private function recap(User $coach, array $payload): NotificationOutbox
    {
        return $this->ligne(NotificationType::CoachTemplateRecap, $coach, ['template_id' => 3] + $payload);
    }

    public function test_le_recap_dit_le_volume_et_la_plage(): void
    {
        $coach = User::factory()->coach()->create();
        $ligne = $this->recap($coach, ['count' => 5, 'from' => '2026-09-06', 'to' => '2026-11-30']);

        $this->assertSame('5 séances · 6 sept. → 30 nov.', $this->renderer()->render($ligne)['body']);
    }

    public function test_le_recap_d_une_seule_seance_reste_au_singulier(): void
    {
        $coach = User::factory()->coach()->create();
        // Bornes absentes : le volume seul, plutôt qu'une plage bancale.
        $ligne = $this->recap($coach, ['count' => 1]);

        $this->assertSame('1 séance', $this->renderer()->render($ligne)['body']);
    }

    public function test_les_bornes_du_recap_ne_glissent_pas_dans_un_fuseau_negatif(): void
    {
        // Guadeloupe (UTC−4) est proposée dans les réglages du club. Les bornes sont des dates NUES
        // (`toDateString()`) : les traiter comme des instants les ramenait à la veille 20 h, donc au
        // jour précédent — les deux bornes du récap étaient fausses.
        ClubSettings::current()->update(['timezone' => 'America/Guadeloupe']);

        $coach = User::factory()->coach()->create();
        $ligne = $this->recap($coach, ['count' => 5, 'from' => '2026-09-06', 'to' => '2026-11-30']);

        $this->assertSame('5 séances · 6 sept. → 30 nov.', $this->renderer()->render($ligne)['body']);

        // Contrôle positif apparié : le fuseau EST bien appliqué là où il doit l'être, sur un vrai
        // instant. Sans lui, l'assertion ci-dessus passerait aussi si le réglage était ignoré.
        $seance = $this->seance();  // 18:00 à Paris = 12:00 en Guadeloupe
        $ligneSeance = $this->ligne(NotificationType::SessionCancelled, $coach, $seance->payloadNotification());

        $this->assertSame('Natation jeunes · sam. 5 sept. · 12:00', $this->renderer()->render($ligneSeance)['body']);
    }

    // ── Bout en bout : de l'annulation de séance à la page Alertes ──

    public function test_le_parent_distingue_ses_alertes_de_celles_de_son_enfant(): void
    {
        [$garant, $enfant] = $this->famille();
        $seance = $this->seance('Natation jeunes');
        $sienne = $this->seance('Trail du dimanche');

        // Le garant est inscrit à SA séance, l'enfant à la sienne : les deux annulations
        // atterrissent dans la même boîte.
        foreach ([[$enfant, $seance], [$garant, $sienne]] as [$sujet, $s]) {
            $s->registrations()->create([
                'user_id' => $sujet->id, 'status' => 'participating', 'registered_at' => Carbon::now(),
            ]);
        }

        app(SessionNotificationService::class)->notifyParticipants($seance, NotificationType::SessionCancelled);
        app(SessionNotificationService::class)->notifyParticipants($sienne, NotificationType::SessionCancelled);

        NotificationOutbox::where('channel', 'push')
            ->update(['status' => 'sent', 'sent_at' => Carbon::now()]);

        $this->actingAs($garant)->get('/alertes')
            ->assertOk()
            ->assertSee('Hugo · Annulation de séance')   // celle de l'enfant, nommée
            ->assertSee('Natation jeunes')
            ->assertSee('Trail du dimanche');            // la sienne, présente

        // Assertion négative appariée aux contrôles positifs ci-dessus : sa propre alerte n'est
        // pas préfixée d'un prénom.
        $this->actingAs($garant)->get('/alertes')
            ->assertDontSee('Claire · Annulation de séance');
    }
}
