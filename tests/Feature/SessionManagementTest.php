<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function discipline(): Discipline
    {
        return Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
    }

    public function test_planning_lists_sessions_in_window(): void
    {
        $user = User::factory()->create();
        $coach = User::factory()->coach()->create();
        // Séance dans la semaine courante (milieu de semaine pour rester dans la fenêtre lun→dim).
        $inWeek = Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(12, 0);
        Session::create([
            'kind' => 'training', 'title' => 'Natation midi',
            'start_at' => $inWeek,
            'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($user)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Natation midi');
    }

    public function test_coach_can_create_session(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'CAP endurance')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 60)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', ['title' => 'CAP endurance', 'created_by' => $coach->id]);
    }

    public function test_athlete_cannot_create_session(): void
    {
        $athlete = User::factory()->create(['roles' => ['athlete']]);

        Livewire::actingAs($athlete)->test(SessionForm::class)->assertForbidden();
    }

    public function test_non_coach_cannot_be_added_as_session_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $notCoach = User::factory()->create(['roles' => ['athlete']]);

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'CAP endurance')
            ->set('discipline_id', $this->discipline()->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 60)
            ->set('coach_ids', [$notCoach->id])
            ->call('save')
            ->assertHasErrors('coach_ids');
    }

    public function test_competition_requires_event_type(): void
    {
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'competition')
            ->set('title', 'Triathlon de Nantes')
            ->set('discipline_id', $this->discipline()->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('event_type_id', null)
            ->call('save')
            ->assertHasErrors('event_type_id');
    }

    public function test_coach_can_cancel_and_restore_future_session(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Vélo HT',
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->set('cancelCheck', true)
            ->call('cancel');
        $this->assertNotNull($session->fresh()->cancelled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cancel_session', 'session_id' => $session->id]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session->fresh()])
            ->call('restore');
        $this->assertNull($session->fresh()->cancelled_at);
    }

    public function test_capacity_increase_via_form_promotes_waitlist(): void
    {
        // E2 bout-en-bout : édition d'une séance pleine (cap 1) + 1 en file capacity.
        // Hausse à 2 via le formulaire (saveSilently) → l'athlète en file passe participating.
        $coach = User::factory()->coach()->create();
        $session = $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'duration_min' => 60, 'capacity' => 1, 'created_by' => $coach->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).

        $service = app(RegistrationService::class);
        $a = $this->athlete();
        $b = $this->athlete();
        $service->register($session, $a, $a); // participating
        $regB = $service->register($session, $b, $b); // waitlist capacity
        $this->assertSame('waitlist', $regB->fresh()->status);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('capacity', 2)
            ->call('saveSilently');

        $this->assertSame(2, $session->fresh()->capacity);
        $this->assertSame('participating', $regB->fresh()->status);
        $this->assertNotNull($regB->fresh()->promoted_at);
    }

    public function test_fiche_shows_target_categories(): void
    {
        // Section « Ciblage » (screen-fiche.jsx FInfos) : les catégories d'âge acceptées sont
        // affichées sur la fiche, triées par sort_order.
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Natation jeunes',
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 75, 'created_by' => $coach->id,
        ]);
        $benj = Category::create(['label' => 'Benjamins', 'age_min' => 12, 'age_max' => 13, 'sort_order' => 4]);
        $min = Category::create(['label' => 'Minimes', 'age_min' => 14, 'age_max' => 15, 'sort_order' => 5]);
        $session->categories()->sync([$min->id, $benj->id]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->assertSee('Ciblage')
            ->assertSee('Benjamins')
            ->assertSee('Minimes')
            // Tri par sort_order : Benjamins (4) avant Minimes (5).
            ->assertSeeInOrder(['Benjamins', 'Minimes']);
    }

    /**
     * Pas de chevron retour en CRÉATION (2026-08-02) : « Créer une séance » est une entrée de
     * navigation permanente (sidebar + bottom-nav) qu'on atteint depuis n'importe où — aucune
     * destination de retour n'est la bonne, et les autres écrans de nav n'en ont pas non plus.
     * En édition on vient toujours d'une fiche précise : le chevron y garde du sens.
     */
    public function test_the_creation_form_has_no_back_chevron(): void
    {
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->assertDontSee('window.clubBack', false)
            ->assertDontSee('Retour fiche');
    }

    public function test_the_edit_form_keeps_a_back_chevron_to_the_session(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'À modifier',
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->assertSee('window.clubBack', false)
            ->assertSee(route('sessions.show', $session), false);
    }

    public function test_past_session_cannot_be_restored(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Passée',
            'start_at' => Carbon::now()->subWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
        // cancelled_at n'est pas mass-assignable (posé par le flow) → forceFill comme en prod.
        $session->forceFill(['cancelled_at' => Carbon::now()->subWeek()])->save();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->call('restore')
            ->assertForbidden();

        $this->assertNotNull($session->fresh()->cancelled_at);
    }

    // ── Accusé de réception : le bouton n'est armé que la case cochée (§4.17) ──

    public function test_cancelling_without_ticking_the_box_does_nothing(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Future', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        // Garde SERVEUR : le bouton grisé ne protège que le clic, pas une action forgée.
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->call('openCancelConfirm')
            ->assertSet('cancelCheck', false)
            ->call('cancel')
            ->assertSet('confirmingCancel', true);   // le dialog reste ouvert

        $this->assertNull($session->fresh()->cancelled_at);
    }

    public function test_the_box_is_never_pre_ticked_when_reopening(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Future', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->call('openCancelConfirm')
            ->set('cancelCheck', true)
            ->call('dismissCancelConfirm')
            ->assertSet('cancelCheck', false)
            ->call('openCancelConfirm')
            ->assertSet('cancelCheck', false);
    }

    /**
     * Le libellé de l'accusé compte des personnes : il doit compter juste, y compris aux deux bornes.
     * « 0 inscrit·e·s seront prévenu·e·s » demandait d'accuser réception d'un envoi qui n'aura pas
     * lieu, et « 1 inscrit·e·s » n'est pas du français.
     */
    public function test_the_acknowledgement_counts_participants_correctly(): void
    {
        $coach = User::factory()->coach()->create();
        $service = app(RegistrationService::class);

        $vide = $this->sessionAt($coach, Carbon::now()->addWeek(), 60);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $vide])
            ->call('openCancelConfirm')->html();
        $this->assertStringContainsString('aucun·e inscrit·e ne sera prévenu·e', $html);
        // Assertion cadrée sur la phrase d'accusé : la fiche affiche par ailleurs, légitimement, une
        // pastille « 0 inscrit·e·s » — une assertion sur la page entière la prendrait pour le défaut.
        $this->assertStringNotContainsString('0 inscrit', $this->accuse($html));

        $un = $this->targetCategory($this->sessionAt($coach, Carbon::now()->addWeek(), 60));
        $service->register($un, $a = $this->athlete(), $a);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $un->fresh()])
            ->call('openCancelConfirm')->html();
        $this->assertStringContainsString('1 inscrit·e sera prévenu·e', $html);

        // Contrôle positif : au pluriel, la phrase reste celle qu'annonce la convention (§4.17).
        $service->register($un, $b = $this->athlete(), $b);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $un->fresh()])
            ->call('openCancelConfirm')->html();
        $this->assertStringContainsString('2 inscrit·e·s seront prévenu·e·s', $html);
    }

    /** Le seul contenu de la phrase d'accusé du dialog d'annulation (repérée par l'id de son <span>). */
    private function accuse(string $html): string
    {
        $this->assertMatchesRegularExpression('/id="txt-annuler-seance"[^>]*>(.*?)<\/span>/s', $html,
            'la phrase d\'accusé de réception est absente du dialog');
        preg_match('/id="txt-annuler-seance"[^>]*>(.*?)<\/span>/s', $html, $m);

        return $m[1];
    }

    public function test_the_confirmation_wording_names_the_definitive_effect_once_started(): void
    {
        $coach = User::factory()->coach()->create();
        $enCours = $this->sessionAt($coach, Carbon::now()->subMinutes(5), 60);

        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $enCours])
            ->call('openCancelConfirm')->html();

        // « Je comprends » sans le « que » : sur une séance sans inscription, la phrase s'élide en
        // « Je comprends qu'aucun·e inscrit·e… » (cf. test_the_acknowledgement_counts_participants_correctly).
        $this->assertStringContainsString('Je comprends', $this->accuse($html));
        $this->assertStringContainsString('définitive', $this->accuse($html));
    }

    // ── Borne d'annulation : la fin du créneau, pas le début (§4.7) ──

    private function sessionAt(User $coach, Carbon $start, int $duree = 60): Session
    {
        return Session::create([
            'kind' => 'training', 'title' => 'Créneau', 'discipline_id' => $this->discipline()->id,
            'start_at' => $start, 'duration_min' => $duree, 'created_by' => $coach->id,
        ]);
    }

    public function test_a_session_can_still_be_cancelled_once_started(): void
    {
        // Orage à 18h35 sur un créneau de 18h30 : le coach annule sur place, les inscrits sont prévenus.
        $coach = User::factory()->coach()->create();
        $session = $this->sessionAt($coach, Carbon::now()->subMinutes(5), 60);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->set('cancelCheck', true)
            ->call('cancel')
            ->assertHasNoErrors();

        $this->assertNotNull($session->fresh()->cancelled_at);
    }

    public function test_a_finished_session_cannot_be_cancelled(): void
    {
        $coach = User::factory()->coach()->create();
        $session = $this->sessionAt($coach, Carbon::now()->subMinutes(90), 60);   // terminée depuis 30 min

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->set('cancelCheck', true)
            ->call('cancel')
            ->assertForbidden();

        $this->assertNull($session->fresh()->cancelled_at);
    }

    public function test_the_cancel_action_disappears_once_the_slot_is_over(): void
    {
        $coach = User::factory()->coach()->create();

        // Contrôle positif : pendant le créneau, l'action est offerte dans les DEUX coquilles.
        $enCours = $this->sessionAt($coach, Carbon::now()->subMinutes(5), 60);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $enCours])->html();
        $this->assertStringContainsString('openCancelConfirm', $this->coquilleMobile($html));
        $this->assertStringContainsString('openCancelConfirm', $html);

        $terminee = $this->sessionAt($coach, Carbon::now()->subMinutes(90), 60);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $terminee])->html();
        $this->assertStringNotContainsString('openCancelConfirm', $html);
    }

    public function test_the_confirmation_announces_that_a_started_session_cannot_be_restored(): void
    {
        $coach = User::factory()->coach()->create();

        $future = $this->sessionAt($coach, Carbon::now()->addWeek(), 60);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $future])
            ->call('openCancelConfirm')->html();
        $this->assertStringContainsString('Réversible', $html);
        $this->assertStringNotContainsString('Irréversible', $html);

        $enCours = $this->sessionAt($coach, Carbon::now()->subMinutes(5), 60);
        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $enCours])
            ->call('openCancelConfirm')->html();
        $this->assertStringContainsString('Irréversible', $html);
        $this->assertStringContainsString('elle ne pourra pas être réactivée', $html);
    }

    /**
     * Portion mobile du rendu (la fiche porte DEUX coquilles, .fiche-mobile et .fiche-desktop, dans
     * le même HTML : une assertion sur la page entière ne dirait pas laquelle porte l'action).
     */
    private function coquilleMobile(string $html): string
    {
        $debut = strpos($html, 'fiche-mobile');
        $fin = strpos($html, 'fiche-desktop');
        $this->assertNotFalse($debut, 'coquille mobile absente du rendu');
        $this->assertNotFalse($fin, 'coquille desktop absente du rendu');

        return substr($html, $debut, $fin - $debut);
    }

    /** Portion desktop du rendu — pendant de coquilleMobile(), pour les mêmes raisons. */
    private function coquilleDesktop(string $html): string
    {
        $debut = strpos($html, 'fiche-desktop');
        $this->assertNotFalse($debut, 'coquille desktop absente du rendu');

        return substr($html, $debut);
    }

    /**
     * L'intertitre « Gestion » coiffe trois actions, toutes devenues indisponibles sur un créneau
     * terminé : inscrire un athlète et remplir la file s'arrêtent au début, l'annulation à la fin.
     * Sans garde, la colonne desktop rendait un titre seul, sans rien dessous.
     */
    public function test_the_gestion_heading_is_not_rendered_without_any_action(): void
    {
        $coach = User::factory()->coach()->create();

        // Contrôle positif : pendant le créneau, l'intertitre coiffe bien l'annulation.
        $enCours = $this->sessionAt($coach, Carbon::now()->subMinutes(5), 60);
        $desktop = $this->coquilleDesktop(
            Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $enCours])->html()
        );
        $this->assertStringContainsString('Gestion', $desktop);
        $this->assertStringContainsString('openCancelConfirm', $desktop);

        $terminee = $this->sessionAt($coach, Carbon::now()->subMinutes(90), 60);
        $desktop = $this->coquilleDesktop(
            Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $terminee])->html()
        );
        $this->assertStringNotContainsString('Gestion', $desktop);
    }

    /**
     * La rangée d'accusé de réception est un <div> cliquable — non focusable. Le x-check qu'elle
     * porte est, lui, un vrai <button> : il doit porter le toggle pour que la case, donc le bouton
     * qu'elle arme, reste atteignable au clavier seul.
     */
    public function test_the_acknowledgement_box_is_operable_without_a_mouse(): void
    {
        $coach = User::factory()->coach()->create();
        $session = $this->sessionAt($coach, Carbon::now()->addWeek(), 60);

        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])
            ->call('openCancelConfirm')->html();

        $this->assertMatchesRegularExpression(
            '/<button[^>]+wire:click\.stop="\$toggle\((&#039;|\x27)cancelCheck/',
            $html,
            'la case doit être un bouton portant elle-même le toggle'
        );
    }

    public function test_cancel_action_is_reachable_on_mobile(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Future', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        $mobile = $this->coquilleMobile(
            Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])->html()
        );

        $this->assertStringContainsString('openCancelConfirm', $mobile);
        // Contrôle positif : « Inscrire un athlète » y était déjà — l'annulation était la seule
        // action d'encadrement à ne vivre que dans la colonne desktop.
        $this->assertStringContainsString('openAthletePicker', $mobile);
    }

    public function test_cancel_action_is_hidden_on_mobile_for_an_athlete(): void
    {
        $athlete = User::factory()->create();
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Future', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        $mobile = $this->coquilleMobile(
            Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $session])->html()
        );

        $this->assertStringNotContainsString('openCancelConfirm', $mobile);
    }

    public function test_cancel_action_is_absent_on_mobile_for_a_cancelled_session(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Annulée', 'discipline_id' => $this->discipline()->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
        $session->forceFill(['cancelled_at' => Carbon::now()])->save();

        $mobile = $this->coquilleMobile(
            Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session->fresh()])->html()
        );

        $this->assertStringNotContainsString('openCancelConfirm', $mobile);
        $this->assertStringContainsString('restore', $mobile);   // contrôle positif : la restauration, elle, y est
    }
}
