<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Garde catégorielle côté UI (§4.5) : sur la fiche, le bouton d'inscription est remplacé par un
 * message quand l'athlète est hors catégorie ou sans catégorie active. Complète la garde
 * policy/service. (L'inscription se fait uniquement depuis la fiche — pas de bouton planning.)
 */
class EnrollCategoryGuardUiTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $label, int $sort): Category
    {
        return Category::create(['label' => $label, 'age_min' => 10 + $sort, 'age_max' => 11 + $sort, 'sort_order' => $sort]);
    }

    private function makeSession(string $title, array $categoryIds): Session
    {
        // Milieu de semaine courante : la séance reste dans la fenêtre lun→dim de la vue planning
        // par défaut (cf. PlanningCategoryFilterTest), et future si on n'est pas en fin de semaine.
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(19, 0);
        if ($start->isPast()) {
            $start = Carbon::now()->addMinutes(90);
        }
        $s = Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $start,
            'duration_min' => 60, 'capacity' => 10,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $s->categories()->sync($categoryIds);

        return $s;
    }

    public function test_fiche_shows_no_category_message_and_no_enroll_button(): void
    {
        $benj = $this->category('Benjamins', 4);
        $s = $this->makeSession('Séance Benjamins', [$benj->id]);
        $athlete = User::factory()->create(); // aucune catégorie active

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Aucune catégorie attribuée à ton compte')
            ->assertDontSee("S'inscrire");
    }

    public function test_fiche_shows_off_category_message_for_categorized_athlete(): void
    {
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $s = $this->makeSession('Séance Minimes', [$min->id]);
        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Cette séance ne concerne pas ta catégorie')
            ->assertDontSee("S'inscrire");
    }

    public function test_fiche_shows_enroll_button_when_targeted(): void
    {
        $benj = $this->category('Benjamins', 4);
        $s = $this->makeSession('Séance Benjamins', [$benj->id]);
        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->assertSee("S'inscrire");
    }

    public function test_category_mismatch_is_flashed_as_readable_message_not_raw_sentinel(): void
    {
        // Chemin réel où la garde service parle directement à l'UI : un coach-athlète s'inscrit
        // LUI-MÊME via le picker bureau (§4.9.7) — actor == target donc pas de bypass staff — sur
        // une séance hors de sa catégorie. Le flash doit être le message traduit, pas la sentinelle.
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $s = $this->makeSession('Séance Minimes', [$min->id]);
        $coach = User::factory()->athleteCoach()->create();
        $coach->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('enrollAthlete', $coach->id)
            ->assertSee('Inscription impossible : la séance ne cible pas une catégorie active')
            ->assertDontSee('category_mismatch');

        $this->assertDatabaseMissing('registrations', ['session_id' => $s->id, 'user_id' => $coach->id]);
    }
}
