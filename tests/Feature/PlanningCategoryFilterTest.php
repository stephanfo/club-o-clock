<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Models\Category;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtrage du planning par catégorie de l'athlète (PRD §4.5) : un athlète voit les séances dont
 * le ciblage inclut au moins une de ses catégories ; fallback ouvert si aucune catégorie ; le
 * staff (coach/admin) voit tout ; le filtrage suit le sujet consulté (parent → enfant, §4.2).
 */
class PlanningCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private function midWeek(): Carbon
    {
        // Milieu de semaine pour rester dans la fenêtre lun→dim de la vue par défaut.
        return Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(12, 0);
    }

    private function makeSession(string $title, array $categoryIds = []): Session
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $this->midWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
        if ($categoryIds) {
            $session->categories()->sync($categoryIds);
        }

        return $session;
    }

    private function category(string $label, int $sort): Category
    {
        return Category::create(['label' => $label, 'age_min' => 10 + $sort, 'age_max' => 11 + $sort, 'sort_order' => $sort]);
    }

    public function test_athlete_sees_only_sessions_targeting_his_categories(): void
    {
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);

        $this->makeSession('Séance Benjamins', [$benj->id]);
        $this->makeSession('Séance Minimes', [$min->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Benjamins')
            ->assertDontSee('Séance Minimes');
    }

    public function test_categorized_athlete_sees_untargeted_open_session(): void
    {
        // Une séance sans aucune catégorie ciblée est ouverte à toutes les catégories (§4.5
        // « par défaut, une nouvelle séance cible toutes les catégories actives ») — même
        // sémantique que SessionNotificationService. Elle reste visible d'un athlète catégorisé.
        $benj = $this->category('Benjamins', 4);
        $this->makeSession('Séance ouverte');

        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance ouverte');
    }

    public function test_athlete_sees_session_targeting_a_surclassement(): void
    {
        // Une des catégories rattachées (même non-principale) suffit à rendre la séance visible.
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $this->makeSession('Séance Minimes', [$min->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);
        $athlete->categories()->attach($min->id, ['is_primary' => false]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Minimes');
    }

    public function test_athlete_without_category_sees_all_sessions(): void
    {
        // Fallback ouvert (§4.5 « Athlète sans catégorie active ») : tout visible, l'inscription
        // reste bloquée par ailleurs.
        $benj = $this->category('Benjamins', 4);
        $this->makeSession('Séance Benjamins', [$benj->id]);
        $this->makeSession('Séance ouverte');

        $athlete = User::factory()->create();

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Benjamins')
            ->assertSee('Séance ouverte');
    }

    public function test_athlete_with_only_archived_category_gets_open_fallback(): void
    {
        // Une catégorie archivée ne compte pas (cohérent avec la garde d'inscription §4.5) :
        // le sujet retombe sur le fallback ouvert et voit tout.
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $benj->update(['archived_at' => Carbon::now()]);
        $this->makeSession('Séance Minimes', [$min->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Minimes');
    }

    public function test_archived_category_does_not_extend_visibility(): void
    {
        // Athlète : Minimes (active) + Benjamins (archivée). La séance ciblant uniquement la
        // catégorie archivée n'est pas visible — seules les catégories actives filtrent.
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $benj->update(['archived_at' => Carbon::now()]);
        $this->makeSession('Séance Benjamins', [$benj->id]);
        $this->makeSession('Séance Minimes', [$min->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($min->id, ['is_primary' => true]);
        $athlete->categories()->attach($benj->id, ['is_primary' => false]);

        Livewire::actingAs($athlete)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Minimes')
            ->assertDontSee('Séance Benjamins');
    }

    public function test_coach_sees_all_sessions_regardless_of_category(): void
    {
        $benj = $this->category('Benjamins', 4);
        $this->makeSession('Séance Benjamins', [$benj->id]);

        $coach = User::factory()->coach()->create();

        Livewire::actingAs($coach)->test(Planning::class)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Benjamins');
    }

    public function test_category_scope_follows_consulted_child(): void
    {
        // Le parent consulte un enfant Benjamin : il voit la séance Benjamins de l'enfant, pas la
        // séance Minimes (§4.2 sélecteur de sujet, §4.5 filtrage).
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);
        $this->makeSession('Séance Benjamins', [$benj->id]);
        $this->makeSession('Séance Minimes', [$min->id]);

        $parent = User::factory()->create();
        $child = User::factory()->create(['guardian_id' => $parent->id]);
        $child->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($parent)->test(Planning::class)
            ->call('setSubject', $child->id)
            ->set('anchor', Carbon::now()->toDateString())
            ->assertSee('Séance Benjamins')
            ->assertDontSee('Séance Minimes');
    }
}
