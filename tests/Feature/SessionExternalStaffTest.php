<?php

namespace Tests\Feature;

use App\Livewire\Admin\TemplateForm;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\StatsService;
use App\Services\TemplateGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Intervenant extérieur sur une séance d'entraînement (#38, PRD §4.11.4).
 *
 * Le club fait appel à un surveillant de baignade prestataire, qui n'est pas adhérent et n'a pas
 * de compte : la séance est encadrée, mais rien ne le disait. Le libellé porte l'information
 * « il y a un intervenant » SANS nommer personne — c'est une propriété de la séance, pas une
 * personne (aucune donnée nominative stockée, minimisation RGPD).
 */
class SessionExternalStaffTest extends TestCase
{
    use RefreshDatabase;

    private Discipline $discipline;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12));
        $this->discipline = Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ affichage (§4.11.4)

    public function test_le_libelle_saffiche_dans_le_bloc_encadrement(): void
    {
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade (prestataire)']);

        Livewire::actingAs(User::factory()->create())->test(SessionShow::class, ['session' => $s])
            ->assertSee('Surveillant de baignade (prestataire)');
    }

    /**
     * Le bandeau §4.11.4 se tait quand un intervenant extérieur est signalé : la séance EST
     * encadrée. Contrôle positif apparié — sans coach NI libellé, le bandeau est bien là,
     * sinon l'assertion négative ne vaudrait rien.
     */
    public function test_le_bandeau_pas_de_coach_se_tait_quand_un_intervenant_est_signale(): void
    {
        $viewer = User::factory()->create();

        $avec = $this->seance(['external_staff_label' => 'Surveillant de baignade']);
        Livewire::actingAs($viewer)->test(SessionShow::class, ['session' => $avec])
            ->assertDontSee('Pas de coach inscrit');

        $sans = $this->seance();
        Livewire::actingAs($viewer)->test(SessionShow::class, ['session' => $sans])
            ->assertSee('Pas de coach inscrit');
    }

    /** Un coach du club ET un surveillant externe coexistent légitimement — les coachs en premier. */
    public function test_un_coach_et_un_intervenant_exterieur_saffichent_tous_les_deux(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Vincent', 'last_name' => 'Durand']);
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade']);
        $s->coaches()->sync([$coach->id]);

        $vue = Livewire::actingAs(User::factory()->create())->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('Vincent Durand')
            ->assertSee('Surveillant de baignade')
            ->assertDontSee('Pas de coach inscrit');

        // Les coachs du club d'abord, l'intervenant ensuite. On compare les PREMIÈRES occurrences :
        // l'onglet est rendu deux fois (mobile + desktop) dans le même HTML, et un assertSeeInOrder
        // passerait en enjambant les deux rendus quel que soit l'ordre réel.
        $html = $vue->html();
        $this->assertLessThan(mb_strpos($html, 'Intervenant extérieur'), mb_strpos($html, 'Vincent Durand'));
    }

    // ------------------------------------------------------------------ compteur admin (§4.16.1)

    public function test_une_seance_avec_intervenant_sort_du_compteur_sans_coach(): void
    {
        $this->seance(['start_at' => Carbon::now()->addWeek(), 'external_staff_label' => 'Surveillant de baignade']);
        $this->seance(['start_at' => Carbon::now()->addWeek()]);

        $stats = app(StatsService::class);
        $p = $stats->resolvePeriod('season');
        $ca = $stats->coachActivity(['from' => $p['from'], 'to' => $p['to'], 'discipline_id' => null, 'category_id' => null]);

        $this->assertSame(1, $ca['future_without_coach']);
    }

    // ------------------------------------------------------------------ périmètre training (garde serveur)

    /**
     * Refus GARDÉ CÔTÉ SERVEUR : le champ n'est affiché que sur une `training`, mais l'état vient
     * du client — une requête forgée sur une compétition ne doit rien écrire.
     */
    public function test_une_competition_ne_peut_pas_porter_de_libelle_meme_en_forgeant(): void
    {
        $type = EventType::create(['label' => 'Triathlon S', 'sort_order' => 1]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionForm::class)
            ->set('kind', 'competition')
            ->set('title', 'Triathlon de Vichy')
            ->set('event_type_id', $type->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('external_staff_label', 'Surveillant de baignade')
            ->call('save');

        $this->assertNull(Session::where('title', 'Triathlon de Vichy')->sole()->external_staff_label);
    }

    public function test_un_entrainement_enregistre_bien_le_libelle(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Piscine du dimanche')
            ->set('discipline_id', $this->discipline->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 90)
            ->set('external_staff_label', 'Surveillant de baignade (prestataire)')
            ->call('save');

        $this->assertSame(
            'Surveillant de baignade (prestataire)',
            Session::where('title', 'Piscine du dimanche')->sole()->external_staff_label
        );
    }

    public function test_le_libelle_est_borne_a_120_caracteres(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Piscine du dimanche')
            ->set('discipline_id', $this->discipline->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('external_staff_label', str_repeat('a', 121))
            ->call('save')
            ->assertHasErrors(['external_staff_label' => 'max']);
    }

    // ------------------------------------------------------------------ récurrence (§4.8)

    public function test_le_modele_reporte_le_libelle_sur_les_seances_generees(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->modele($admin, ['external_staff_label' => 'Surveillant de baignade']);

        $seances = app(TemplateGenerationService::class)->generate($t, $admin);

        $this->assertTrue($seances->isNotEmpty());
        foreach ($seances as $s) {
            $this->assertSame('Surveillant de baignade', $s->external_staff_label);
        }
    }

    /** Même garde qu'à la séance : un modèle non-`training` ne propage aucun libellé. */
    public function test_un_modele_non_training_ne_propage_aucun_libelle(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->modele($admin, [
            'kind' => 'club_event', 'discipline_id' => null,
            'external_staff_label' => 'Surveillant de baignade',
        ]);

        $seances = app(TemplateGenerationService::class)->generate($t, $admin);

        $this->assertTrue($seances->isNotEmpty());
        foreach ($seances as $s) {
            $this->assertNull($s->external_staff_label);
        }
    }

    public function test_le_formulaire_de_modele_enregistre_le_libelle_et_le_nullifie_hors_training(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::create(['label' => 'Adultes', 'age_min' => 18, 'age_max' => 99, 'sort_order' => 1]);

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('label', 'Piscine du dimanche')
            ->set('kind', 'training')
            ->set('discipline_id', $this->discipline->id)
            ->set('day_of_week', 7)
            ->set('category_ids', [$cat->id])
            ->set('external_staff_label', 'Surveillant de baignade')
            ->call('save');

        $this->assertSame('Surveillant de baignade', SessionTemplate::where('label', 'Piscine du dimanche')->sole()->external_staff_label);

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('label', 'Sortie club')
            ->set('kind', 'club_event')
            ->set('day_of_week', 6)
            ->set('category_ids', [$cat->id])
            ->set('external_staff_label', 'Surveillant de baignade')
            ->call('save');

        $this->assertNull(SessionTemplate::where('label', 'Sortie club')->sole()->external_staff_label);
    }

    // ------------------------------------------------------------------ fenêtre depuis la fiche

    /**
     * Comme « Inscrire un coach », l'intervenant se règle depuis l'onglet Encadrement sans ouvrir
     * le formulaire d'édition : ouvrir → saisir → enregistrer.
     */
    public function test_un_coach_renseigne_lintervenant_depuis_la_fiche(): void
    {
        $s = $this->seance();

        Livewire::actingAs(User::factory()->coach()->create())->test(SessionShow::class, ['session' => $s])
            ->assertSee('Intervenant extérieur')
            ->call('openExternalStaff')
            ->assertSet('editingExternalStaff', true)
            ->set('externalStaffDraft', '  Surveillant de baignade  ')
            ->call('saveExternalStaff')
            ->assertSet('editingExternalStaff', false)
            ->assertSee('Surveillant de baignade')
            ->assertDontSee('Pas de coach inscrit');

        $this->assertSame('Surveillant de baignade', $s->fresh()->external_staff_label);
    }

    public function test_la_fenetre_se_preremplit_et_modifie_le_libelle_existant(): void
    {
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade']);

        Livewire::actingAs(User::factory()->admin()->create())->test(SessionShow::class, ['session' => $s])
            ->call('openExternalStaff')
            ->assertSet('externalStaffDraft', 'Surveillant de baignade')
            ->set('externalStaffDraft', 'MNS prestataire')
            ->call('saveExternalStaff');

        $this->assertSame('MNS prestataire', $s->fresh()->external_staff_label);
    }

    /** Retirer rend la séance à nouveau « sans coach » : le bandeau revient. */
    public function test_retirer_lintervenant_fait_revenir_le_bandeau(): void
    {
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade']);

        Livewire::actingAs(User::factory()->coach()->create())->test(SessionShow::class, ['session' => $s])
            ->assertDontSee('Pas de coach inscrit')
            ->call('removeExternalStaff')
            ->assertSee('Pas de coach inscrit');

        $this->assertNull($s->fresh()->external_staff_label);
    }

    /** Vider le champ puis enregistrer équivaut à retirer — pas de libellé blanc en base. */
    public function test_enregistrer_un_champ_vide_retire_le_libelle(): void
    {
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade']);

        Livewire::actingAs(User::factory()->coach()->create())->test(SessionShow::class, ['session' => $s])
            ->call('openExternalStaff')
            ->set('externalStaffDraft', '   ')
            ->call('saveExternalStaff');

        $this->assertNull($s->fresh()->external_staff_label);
    }

    public function test_la_fenetre_borne_le_libelle_a_120_caracteres(): void
    {
        $s = $this->seance();

        Livewire::actingAs(User::factory()->coach()->create())->test(SessionShow::class, ['session' => $s])
            ->call('openExternalStaff')
            ->set('externalStaffDraft', str_repeat('a', 121))
            ->call('saveExternalStaff')
            ->assertHasErrors(['externalStaffDraft' => 'max']);

        $this->assertNull($s->fresh()->external_staff_label);
    }

    /** Refus : un athlète ne voit pas le geste, et une requête forgée est rejetée par la policy. */
    public function test_un_athlete_ne_peut_ni_voir_ni_forcer_le_geste(): void
    {
        $s = $this->seance();
        $athlete = User::factory()->create();

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->assertDontSeeHtml('wire:click="openExternalStaff"')
            ->call('saveExternalStaff')
            ->assertForbidden();

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $this->seance(['external_staff_label' => 'Surveillant'])])
            ->call('removeExternalStaff')
            ->assertForbidden();

        $this->assertNull($s->fresh()->external_staff_label);
        // Contrôle positif : le même écran, vu par un coach, propose bien le geste.
        Livewire::actingAs(User::factory()->coach()->create())->test(SessionShow::class, ['session' => $s])
            ->assertSeeHtml('wire:click="openExternalStaff"');
    }

    /**
     * Refus serveur hors fenêtre de gestion : compétition, séance annulée, séance commencée. Le
     * bouton y est masqué, mais l'appel direct ne doit rien écrire.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function seancesHorsGestion(): array
    {
        return [
            'compétition' => [['kind' => 'competition', 'discipline_id' => null]],
            'séance annulée' => [['cancelled_at' => '2026-06-19 10:00:00']],
            'séance commencée' => [['start_at' => '2026-06-20 11:30:00']],
        ];
    }

    /** @param  array<string, mixed>  $attrs */
    #[DataProvider('seancesHorsGestion')]
    public function test_le_geste_est_refuse_hors_fenetre_de_gestion(array $attrs): void
    {
        $s = $this->seance($attrs);
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertDontSeeHtml('wire:click="openExternalStaff"')
            ->call('openExternalStaff')
            ->assertSet('editingExternalStaff', false)
            ->set('externalStaffDraft', 'Surveillant de baignade')
            ->call('saveExternalStaff');

        $this->assertNull($s->fresh()->external_staff_label);
    }

    public function test_le_geste_est_trace_au_journal_daudit_sans_notifier(): void
    {
        Notification::fake();
        $s = $this->seance();
        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('openExternalStaff')
            ->set('externalStaffDraft', 'Surveillant de baignade')
            ->call('saveExternalStaff');

        $this->assertTrue(AuditLog::where('action', 'update_session')->where('session_id', $s->id)->exists());
        $this->assertSame(0, NotificationOutbox::count());
    }

    // ------------------------------------------------------------------ fabriques

    /** @param  array<string, mixed>  $attrs */
    private function seance(array $attrs = []): Session
    {
        // forceFill : `cancelled_at` n'est pas mass-assignable, une séance annulée passe par là.
        $s = new Session;
        $s->forceFill(array_merge([
            'kind' => 'training',
            'title' => 'Natation',
            'discipline_id' => $this->discipline->id,
            'start_at' => Carbon::now()->addDays(3)->setTime(9, 0),
            'duration_min' => 90,
            'created_by' => User::factory()->admin()->create()->id,
        ], $attrs))->save();

        return $s;
    }

    /** @param  array<string, mixed>  $attrs */
    private function modele(User $admin, array $attrs = []): SessionTemplate
    {
        return SessionTemplate::create(array_merge([
            'label' => 'Piscine du dimanche',
            'kind' => 'training',
            'discipline_id' => $this->discipline->id,
            'day_of_week' => 7,
            'start_time_of_day' => '09:00',
            'duration_min' => 90,
            'generation_start_date' => Carbon::now()->toDateString(),
            'generation_end_date' => Carbon::now()->addWeeks(3)->toDateString(),
            'created_by' => $admin->id,
            'status' => 'active',
        ], $attrs));
    }
}
