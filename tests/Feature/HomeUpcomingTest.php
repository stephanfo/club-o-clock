<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Models\Category;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accueil (§2, §4.5) : « Mes prochaines séances » liste les séances où le sujet est inscrit,
 * place ferme (participating) ou liste d'attente (waitlist, §4.9.4) ; la carte héros « prochaine
 * séance » reste la prochaine du club mais filtrée par la catégorie du sujet. Le tout suit le
 * sujet consulté (parent → enfant, §4.2).
 */
class HomeUpcomingTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $title, Carbon $start, array $categoryIds = []): Session
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $start, 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
        if ($categoryIds) {
            $session->categories()->sync($categoryIds);
        }

        return $session;
    }

    private function register(Session $s, User $u, string $status = 'participating'): void
    {
        Registration::create([
            'session_id' => $s->id, 'user_id' => $u->id,
            'status' => $status, 'registered_at' => Carbon::now(),
        ]);
    }

    private function category(string $label, int $sort): Category
    {
        return Category::create(['label' => $label, 'age_min' => 10 + $sort, 'age_max' => 11 + $sort, 'sort_order' => $sort]);
    }

    public function test_my_upcoming_lists_only_sessions_the_subject_is_registered_to(): void
    {
        $athlete = User::factory()->create();

        // La plus proche n'est PAS inscrite : elle occupe le héros, mais reste hors de la liste perso.
        $pasInscrit = $this->makeSession('Pas inscrit', Carbon::now()->addDays(1));
        $inscrit = $this->makeSession('Inscrit A', Carbon::now()->addDays(2));
        $inscritB = $this->makeSession('Inscrit B', Carbon::now()->addDays(3));

        $this->register($inscrit, $athlete);
        $this->register($inscritB, $athlete);
        // Pas d'inscription sur $pasInscrit.

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('next', fn ($n) => $n->title === 'Pas inscrit')
            ->assertViewHas('myUpcoming', fn ($my) => $my->pluck('title')->sort()->values()->all() === ['Inscrit A', 'Inscrit B']);
    }

    public function test_waitlist_registration_is_listed_in_my_upcoming(): void
    {
        // §4.9.4 : la position en liste d'attente doit être visible à l'athlète. L'accueil
        // liste donc aussi les inscriptions waitlist (la carte affiche un chip de statut distinct).
        // La plus proche non inscrite occupe le héros, pour que la waitlist reste dans la liste perso.
        $athlete = User::factory()->create();
        $this->makeSession('Héros club', Carbon::now()->addDays(1));
        $waitlisted = $this->makeSession('Liste attente', Carbon::now()->addDays(2));
        $this->register($waitlisted, $athlete, 'waitlist');

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('myUpcoming', fn ($my) => $my->pluck('title')->all() === ['Liste attente']);
    }

    public function test_cancelled_registration_is_excluded_from_my_upcoming(): void
    {
        $athlete = User::factory()->create();
        $cancelled = $this->makeSession('Désinscrit', Carbon::now()->addDays(1));
        $this->register($cancelled, $athlete, 'cancelled');

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('myUpcoming', fn ($my) => $my->isEmpty());
    }

    public function test_hero_session_is_excluded_from_my_upcoming_to_avoid_duplicate(): void
    {
        $athlete = User::factory()->create();
        // La toute prochaine séance du club est aussi une inscription → elle devient le héros,
        // et ne doit pas réapparaître dans la liste perso.
        $premiere = $this->makeSession('Héros', Carbon::now()->addDays(1));
        $seconde = $this->makeSession('Suivante', Carbon::now()->addDays(2));
        $this->register($premiere, $athlete);
        $this->register($seconde, $athlete);

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('next', fn ($n) => $n->title === 'Héros')
            ->assertViewHas('myUpcoming', fn ($my) => $my->pluck('title')->all() === ['Suivante']);
    }

    public function test_hero_next_session_is_filtered_by_subject_category(): void
    {
        $benj = $this->category('Benjamins', 4);
        $min = $this->category('Minimes', 5);

        // La plus proche cible une AUTRE catégorie → ne doit pas devenir le héros du sujet.
        $this->makeSession('Minimes proche', Carbon::now()->addDays(1), [$min->id]);
        $this->makeSession('Benjamins plus loin', Carbon::now()->addDays(2), [$benj->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($benj->id, ['is_primary' => true]);

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('next', fn ($n) => $n->title === 'Benjamins plus loin');
    }

    public function test_subject_without_category_sees_next_session_via_open_fallback(): void
    {
        $benj = $this->category('Benjamins', 4);
        $this->makeSession('Ciblée', Carbon::now()->addDays(1), [$benj->id]);

        // Aucun ciblage catégorie sur le sujet → fallback ouvert (voit tout).
        $athlete = User::factory()->create();

        Livewire::actingAs($athlete)->test(Home::class)
            ->assertViewHas('next', fn ($n) => $n->title === 'Ciblée');
    }
}
