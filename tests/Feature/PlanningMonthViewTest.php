<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vue Mois du planning : la cellule d'un jour porte le type de séance ET l'état de participation
 * du sujet consulté (§4.2). Mobile = pastilles pleines (participe) / creuses (non inscrit) ;
 * desktop = mini-cartes <x-session-card variant="pill">. Un seul arbre DOM, bascule CSS.
 */
class PlanningMonthViewTest extends TestCase
{
    use RefreshDatabase;

    /** Milieu de mois : garde la séance dans la fenêtre de la vue Mois quel que soit le jour du test. */
    private function midMonth(int $hour = 12): Carbon
    {
        return Carbon::now()->startOfMonth()->addDays(14)->setTime($hour, 0);
    }

    private function makeSession(string $title, int $hour = 12): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $this->midMonth($hour), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
    }

    private function enroll(Session $session, User $user, string $status = 'participating'): Registration
    {
        return Registration::create([
            'session_id' => $session->id, 'user_id' => $user->id,
            'status' => $status, 'registered_at' => Carbon::now(),
        ]);
    }

    /** Passe le composant en vue Mois sur le mois courant. */
    private function month(User $actor): Testable
    {
        return Livewire::actingAs($actor)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->call('setView', 'month');
    }

    public function test_dot_is_filled_when_subject_participates_and_hollow_otherwise(): void
    {
        $athlete = User::factory()->create();
        $mine = $this->makeSession('Séance inscrite', 10);
        $this->makeSession('Séance libre', 18);
        $this->enroll($mine, $athlete);

        $html = $this->month($athlete)->html();

        // Pastille pleine (is-in) pour la séance inscrite, creuse (is-free) pour l'autre.
        $this->assertStringContainsString('plan-month-dot is-in', $html);
        $this->assertStringContainsString('plan-month-dot is-free', $html);
    }

    public function test_waitlist_registration_renders_its_own_dot_state(): void
    {
        $athlete = User::factory()->create();
        $session = $this->makeSession('Séance pleine');
        $this->enroll($session, $athlete, 'waitlist');

        $this->month($athlete)->assertSee('plan-month-dot is-wait', false);
    }

    public function test_cancelled_registration_is_not_counted_as_participating(): void
    {
        // Une inscription annulée ramène le sujet à « pas inscrit » → pastille creuse.
        $athlete = User::factory()->create();
        $session = $this->makeSession('Séance quittée');
        $this->enroll($session, $athlete, 'cancelled');

        $html = $this->month($athlete)->html();

        $this->assertStringContainsString('plan-month-dot is-free', $html);
        $this->assertStringNotContainsString('plan-month-dot is-in', $html);
    }

    public function test_cancelled_session_is_dimmed(): void
    {
        $athlete = User::factory()->create();
        $session = $this->makeSession('Séance annulée');
        // `cancelled_at` n'est pas fillable (l'annulation passe par ManagesLifecycle) → forceFill.
        $session->forceFill(['cancelled_at' => Carbon::now()])->save();

        // L'annulation se cumule avec l'état d'inscription (ici « pas inscrit ») sur la pastille,
        // et prime sur le statut dans la mini-carte desktop.
        $this->month($athlete)
            ->assertSee('plan-month-dot is-free is-cancelled', false)
            ->assertSee('scard-cancelled', false)
            ->assertSee('Séance annulée', false);
    }

    public function test_cancelled_session_overrides_the_participation_marker(): void
    {
        // Une séance annulée à laquelle on était inscrit ne doit plus se lire « tu participes » :
        // la pastille reste marquée annulée et la mini-carte affiche l'annulation, pas la coche.
        $athlete = User::factory()->create();
        $session = $this->makeSession('Séance annulée');
        $this->enroll($session, $athlete);
        $session->forceFill(['cancelled_at' => Carbon::now()])->save();

        $html = $this->month($athlete)->html();

        $this->assertStringContainsString('is-in is-cancelled', $html);
        $this->assertStringContainsString('Séance annulée', $html);
        // Le marqueur « participe » de la mini-carte cède la place à celui d'annulation.
        // (« Tu participes » subsiste dans la légende de la grille : on cible donc le title.)
        $this->assertStringNotContainsString('title="Tu participes"', $html);
    }

    public function test_overflowing_day_shows_a_remainder_counter(): void
    {
        // 5 séances le même jour : 4 pastilles + « +1 » (mobile), 3 mini-cartes + « +2 autres » (desktop).
        $athlete = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->makeSession("Séance $i", 8 + $i);
        }

        $html = $this->month($athlete)->html();

        $this->assertStringContainsString('+1', $html);
        $this->assertStringContainsString('+2 autres', $html);
    }

    public function test_desktop_pills_carry_time_and_title(): void
    {
        // Les mini-cartes desktop portent l'information manquante de la vue à pastilles.
        $athlete = User::factory()->create();
        $session = $this->makeSession('Natation technique', 19);
        $this->enroll($session, $athlete);

        $this->month($athlete)
            ->assertSee('scard-pill', false)
            ->assertSee('Natation technique')
            ->assertSee($session->start_at->copy()->setTimezone(ClubSettings::current()->timezone)->format('H:i'))
            // Le title porte le marqueur de la mini-carte (le simple texte serait aussi
            // satisfait par la légende de la grille).
            ->assertSee('title="Tu participes"', false);
    }

    public function test_empty_day_number_is_not_actionable(): void
    {
        // Un jour sans séance mène à une vue Jour vide → pas de bouton (l'affordance CSS et le
        // comportement doivent s'accorder). Seuls les jours porteurs de séances sont cliquables.
        $athlete = User::factory()->create();
        $session = $this->makeSession('Séance du jour');
        $dayStr = $session->start_at->copy()->setTimezone(ClubSettings::current()->timezone)->toDateString();
        $emptyStr = Carbon::parse($dayStr)->addDay()->toDateString();

        $html = $this->month($athlete)->html();

        $this->assertStringContainsString('goToDay(\''.$dayStr.'\')', $html);
        $this->assertStringNotContainsString('goToDay(\''.$emptyStr.'\')', $html);
    }

    public function test_dot_carries_the_discipline_colour(): void
    {
        $athlete = User::factory()->create();
        $swim = Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
        $session = $this->makeSession('Séance natation');
        $session->update(['discipline_id' => $swim->id]);

        $this->month($athlete)->assertSee('dot-swim plan-month-dot', false);
    }

    public function test_markers_follow_the_consulted_child_not_the_parent(): void
    {
        // Garde §4.2 : c'est l'inscription de l'enfant consulté qui pilote les marqueurs,
        // jamais celle du parent connecté.
        $parent = User::factory()->create();
        $child = User::factory()->create(['guardian_id' => $parent->id]);

        $childSession = $this->makeSession('Séance de l\'enfant', 10);
        $parentSession = $this->makeSession('Séance du parent', 18);
        $this->enroll($childSession, $child);
        $this->enroll($parentSession, $parent);

        $html = Livewire::actingAs($parent)->test(Planning::class)
            ->call('setSubject', $child->id)
            ->set('anchor', Carbon::now()->toDateString())
            ->call('setView', 'month')
            ->html();

        // Une seule séance marquée « participe » — celle de l'enfant, pas celle du parent.
        // Le corps mois est rendu deux fois (arbre desktop + arbre mobile, bascule CSS) → 1 par arbre.
        $this->assertSame(2, substr_count($html, 'plan-month-dot is-in'));
        $this->assertStringContainsString('plan-month-dot is-free', $html);
        // Le marqueur nomme l'enfant (« Hugo participe »), pas le parent.
        $this->assertStringContainsString('title="'.$child->first_name.' participe"', $html);
    }

    public function test_overflow_cells_of_the_grid_carry_their_sessions(): void
    {
        // La grille du mois affiche des semaines complètes : les jours débordants (mois précédent /
        // suivant) doivent porter leurs séances, sinon ils paraissent vides à tort.
        $athlete = User::factory()->create();
        $anchor = Carbon::now()->startOfMonth();
        $coach = User::factory()->coach()->create();

        // Un jour de la semaine débordante en tête de grille (avant le 1er du mois).
        $overflowDay = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        if ($overflowDay->isSameMonth($anchor)) {
            // Le mois commence un lundi : pas de débordement en tête, on prend celui de fin.
            $overflowDay = $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        }
        $this->assertFalse($overflowDay->isSameMonth($anchor), 'jour de débordement attendu hors du mois');

        Session::create([
            'kind' => 'training', 'title' => 'Séance débordante',
            'start_at' => $overflowDay->copy()->setTime(18, 0), 'duration_min' => 60,
            'created_by' => $coach->id,
        ]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', $anchor->toDateString())
            ->call('setView', 'month')
            ->assertSee('Séance débordante');
    }

    public function test_view_selector_lists_day_then_week_then_month(): void
    {
        // Ordre de granularité croissante demandé par les utilisateurs…
        // On cible les boutons du segmented (et non les libellés, « Jour » se retrouvant
        // aussi dans « Aujourd'hui » de la barre de navigation).
        $athlete = User::factory()->create();
        $html = Livewire::actingAs($athlete)->test(Planning::class)->html();

        $day = strpos($html, "setView('day')");
        $week = strpos($html, "setView('week')");
        $month = strpos($html, "setView('month')");

        $this->assertNotFalse($day);
        $this->assertLessThan($week, $day);
        $this->assertLessThan($month, $week);
    }

    public function test_default_view_is_still_week(): void
    {
        // …sans changer la vue par défaut.
        $athlete = User::factory()->create();

        Livewire::actingAs($athlete)->test(Planning::class)->assertSet('view', 'week');
    }
}
