<?php

namespace Tests\Feature;

use App\Livewire\Admin\TemplateForm;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\StatsService;
use App\Services\TemplateGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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

    /** Non-régression : un coach du club ET un surveillant externe coexistent légitimement. */
    public function test_un_coach_et_un_intervenant_exterieur_saffichent_tous_les_deux(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Vincent', 'last_name' => 'Durand']);
        $s = $this->seance(['external_staff_label' => 'Surveillant de baignade']);
        $s->coaches()->sync([$coach->id]);

        Livewire::actingAs(User::factory()->create())->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('Vincent Durand')
            ->assertSee('Surveillant de baignade')
            ->assertDontSee('Pas de coach inscrit');
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

    // ------------------------------------------------------------------ fabriques

    /** @param  array<string, mixed>  $attrs */
    private function seance(array $attrs = []): Session
    {
        return Session::create(array_merge([
            'kind' => 'training',
            'title' => 'Natation',
            'discipline_id' => $this->discipline->id,
            'start_at' => Carbon::now()->addDays(3)->setTime(9, 0),
            'duration_min' => 90,
            'created_by' => User::factory()->admin()->create()->id,
        ], $attrs));
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
