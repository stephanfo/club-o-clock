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
}
