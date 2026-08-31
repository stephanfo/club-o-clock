<?php

namespace Tests\Feature;

use App\Models\NotificationOutbox;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Services\CoachRegistrationService;
use App\Services\RegistrationService;
use App\Services\SessionNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Chaîne complète ÉMETTEUR → payload → rendu (§4.15.2, cadrage §7.14).
//
// NotificationContexteSujetTest prouve que le renderer sait dire QUELLE séance — mais il construit
// ses payloads à la main. Rien n'obligeait donc les émetteurs à les lui donner : rétablir
// `['session_id' => $session->id]` dans un service laissait toute la suite verte, et les
// notifications de ce service redevenaient muettes en silence. C'est exactement le défaut d'origine.
//
// Ce fichier ferme la boucle : un test par point d'émission, qui joue le VRAI service et lit la
// ligne réellement créée. Toute nouvelle notification référençant une séance vient s'y ajouter.
class NotificationSeanceEmiseTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    /** Séance à 19:00 heure du club — l'assertion d'heure ci-dessous en dépend. */
    private function seance(string $titre, ?int $capacity = null, string $kind = 'training'): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => $kind,
            'title' => $titre,
            // Relatif à maintenant (les inscriptions sont fermées sur une séance commencée), mais
            // posé en heure de Paris : le stockage est en UTC, le rendu doit reposer 19:00.
            'start_at' => Carbon::now()->addDays(2)->setTimezone('Europe/Paris')->setTime(19, 0),
            'duration_min' => 90,
            'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ]));
    }

    /**
     * Le cœur du fichier : la notification de ce type dit-elle QUELLE séance et QUAND ?
     *
     * Trois niveaux, du plus précis au plus lisible en cas d'échec : la clé figée par l'émetteur,
     * le rendu qui en découle, et le contrôle apparié — le corps n'est plus la description
     * générique du type, celle qui ne nomme rien et qu'on cherchait précisément à remplacer.
     */
    private function assertDitLaSeance(NotificationType $type, Session $seance): void
    {
        $ligne = NotificationOutbox::where('type', $type->value)->first();
        $this->assertNotNull($ligne, "Aucune ligne {$type->value} : le scénario n'a pas émis la notification attendue.");

        $this->assertArrayHasKey('session_title', $ligne->payload ?? [],
            "L'émetteur de {$type->value} ne fige plus la séance au payload : la notification redevient muette.");

        $corps = app(NotificationRenderer::class)->render($ligne)['body'];
        $this->assertStringContainsString($seance->title, $corps);
        $this->assertStringContainsString('19:00', $corps, 'Le corps doit donner le créneau à l\'heure du club.');
        $this->assertNotSame($type->description(), $corps);
    }

    // ── Événements de séance (§4.7) ──

    public function test_l_annulation_dit_quelle_seance_est_annulee(): void
    {
        $seance = $this->seance('Natation seuil');
        $athlete = $this->athlete();
        app(RegistrationService::class)->register($seance, $athlete, $athlete);

        app(SessionNotificationService::class)->notifyParticipants($seance, NotificationType::SessionCancelled);

        $this->assertDitLaSeance(NotificationType::SessionCancelled, $seance);
    }

    public function test_l_annonce_d_un_evenement_dit_lequel(): void
    {
        $evenement = $this->seance('Interclubs de rentrée', kind: 'competition');
        $this->athlete(); // dans la catégorie ciblée, donc dans l'audience.

        app(SessionNotificationService::class)->notifyEventCreated($evenement);

        $this->assertDitLaSeance(NotificationType::EventCreated, $evenement);
    }

    // ── Producteurs d'inscription (§4.9.7, §4.10.5, §4.10.4) ──

    public function test_l_inscription_par_le_bureau_dit_la_seance(): void
    {
        $seance = $this->seance('Renforcement');
        $athlete = $this->athlete();

        app(RegistrationService::class)->register($seance, $athlete, User::factory()->coach()->create());

        $this->assertDitLaSeance(NotificationType::EnrolledByCoach, $seance);
    }

    public function test_l_inscription_forcee_dit_la_seance(): void
    {
        $seance = $this->seance('Fractionné', capacity: 1);

        app(RegistrationService::class)->overrideRegister(
            $seance, $this->athlete(), User::factory()->coach()->create(), 'remplaçant',
        );

        $this->assertDitLaSeance(NotificationType::CoachOverride, $seance);
    }

    public function test_la_promotion_depuis_la_liste_d_attente_dit_la_seance(): void
    {
        // C'est la notification où la question « laquelle ? » se pose le plus : l'athlète est sur
        // plusieurs files à la fois, et « une place se libère » ne lui dit pas où courir.
        $seance = $this->seance('Sortie longue', capacity: 1);
        $premier = $this->athlete();
        $suivant = $this->athlete();

        app(RegistrationService::class)->register($seance, $premier, $premier);
        app(RegistrationService::class)->register($seance, $suivant, $suivant);
        $this->assertSame('waitlist', Registration::where('user_id', $suivant->id)->firstOrFail()->status);

        app(RegistrationService::class)->cancel($seance, $premier, $premier);

        $this->assertDitLaSeance(NotificationType::WaitlistPromoted, $seance);
    }

    // ── Encadrement (§4.11) ──

    public function test_l_encadrement_dit_la_seance_au_coach_affecte_et_a_ses_co_encadrants(): void
    {
        $seance = $this->seance('Piste — mardi');
        $present = User::factory()->coach()->create();
        $arrivant = User::factory()->coach()->create();

        // Le premier s'inscrit lui-même : ni affectation par un tiers, ni co-encadrant à prévenir.
        app(CoachRegistrationService::class)->register($seance, $present, $present);
        $this->assertSame(0, NotificationOutbox::count());

        // Le second est affecté par un admin → coach_assigned pour lui, coach_registration pour l'autre.
        app(CoachRegistrationService::class)->register($seance, $arrivant, User::factory()->admin()->create());

        $this->assertDitLaSeance(NotificationType::CoachAssigned, $seance);
        $this->assertDitLaSeance(NotificationType::CoachRegistration, $seance);
    }
}
