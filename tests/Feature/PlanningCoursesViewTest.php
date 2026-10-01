<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Models\Category;
use App\Models\Debrief;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vue « Courses » du planning (#106, PRD §4.7) : à venir sans borne haute, date croissante ;
 * passées limitées à la saison en cours, la plus récente en tête, avec débriefs et album ;
 * prénoms des participants selon §4.9.4 ; mêmes filtres de catégorie que le planning.
 */
class PlanningCoursesViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Saison 2026-2027 (bascule en septembre) : le 15 octobre est bien à l'intérieur.
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 12, 0, 0, 'Europe/Paris'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(string $title, Carbon $start, array $attrs = []): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'competition', 'title' => $title,
            'start_at' => $start, 'duration_min' => 120, 'created_by' => $coach->id,
            ...$attrs,
        ]);
    }

    private function inscrire(Session $s, User $u, string $statut = 'participating'): void
    {
        Registration::create(['session_id' => $s->id, 'user_id' => $u->id, 'status' => $statut, 'registered_at' => Carbon::now()]);
    }

    private function courses(User $viewer)
    {
        // Par l'URL (?view=courses) : le rendu initial est du HTML, là où un call() renvoie du JSON
        // aux caractères échappés, illisible pour assertSeeInOrder.
        return Livewire::withQueryParams(['view' => 'courses'])->actingAs($viewer)->test(Planning::class);
    }

    public function test_upcoming_has_no_end_bound_and_past_is_limited_to_current_season(): void
    {
        $this->course('Trail de juin prochain', Carbon::parse('2027-06-20 08:00', 'Europe/Paris'));
        $this->course('Cross de novembre', Carbon::parse('2026-11-08 10:00', 'Europe/Paris'));
        // Le jour même, une course déjà partie reste « à venir » jusqu'à minuit.
        $this->course('Course du jour', Carbon::parse('2026-10-15 09:00', 'Europe/Paris'));
        $this->course('Tri de septembre', Carbon::parse('2026-09-12 09:00', 'Europe/Paris'));
        $this->course('Duathlon d\'octobre', Carbon::parse('2026-10-04 09:00', 'Europe/Paris'));
        $this->course('Course de la saison dernière', Carbon::parse('2026-08-30 09:00', 'Europe/Paris'));

        $coach = User::factory()->coach()->create();
        Session::create(['kind' => 'training', 'title' => 'Natation du mardi', 'start_at' => Carbon::now()->addDays(2), 'duration_min' => 60, 'created_by' => $coach->id]);

        $this->courses(User::factory()->create())
            ->assertSeeInOrder(['À venir', 'Course du jour', 'Cross de novembre', 'Trail de juin prochain',
                'Passées · saison 2026-2027', 'Duathlon d\'octobre', 'Tri de septembre'])
            ->assertDontSee('Course de la saison dernière')
            ->assertDontSee('Natation du mardi');
    }

    public function test_row_shows_event_details_and_participants_first_names(): void
    {
        $course = $this->course('Tri de Dijon', Carbon::now()->addMonth(), ['distance' => 'Sprint', 'location_text' => 'Lac Kir']);
        $this->inscrire($course, User::factory()->create(['first_name' => 'Marc', 'last_name' => 'Simon']));
        $this->inscrire($course, User::factory()->create(['first_name' => 'Julie', 'last_name' => 'Petit']));
        $this->inscrire($course, User::factory()->create(['first_name' => 'Tom', 'last_name' => 'Annule']), 'cancelled');

        $this->courses(User::factory()->create())
            ->assertSee('Sprint · Lac Kir')
            ->assertSee('2 du club')
            ->assertSee('Marc S.')
            ->assertSee('Julie P.')
            ->assertDontSee('Simon')
            ->assertDontSee('Tom A.');

        // Coach consultant : nom complet (§4.9.4).
        $this->courses(User::factory()->coach()->create())->assertSee('Marc Simon');
    }

    public function test_shows_my_intention_upcoming_and_past(): void
    {
        $moi = User::factory()->create();
        $avenir = $this->course('Cross de novembre', Carbon::now()->addMonth());
        $passee = $this->course('Tri de septembre', Carbon::now()->subMonth());
        $this->course('Course sans moi', Carbon::now()->addWeeks(2));
        $this->inscrire($avenir, $moi);
        $this->inscrire($passee, $moi);

        $this->courses($moi)
            ->assertSeeInOrder(['Course sans moi', 'Personne du club', 'Cross de novembre', 'Tu participes', 'Tri de septembre', 'Tu y étais']);

        $this->courses($moi)->set('mine', true)
            ->assertSee('Cross de novembre')
            ->assertDontSee('Course sans moi');
    }

    public function test_past_row_shows_active_debriefs_album_and_links_to_debriefs_tab(): void
    {
        $passee = $this->course('Tri de septembre', Carbon::now()->subMonth(), ['photos_album_url' => 'https://photos.example/album']);
        foreach ([null, null, Carbon::now()] as $archive) {
            Debrief::create(['session_id' => $passee->id, 'author_id' => User::factory()->create()->id, 'content_markdown' => 'Bravo', 'archived_at' => $archive]);
        }
        $sansRien = $this->course('Duathlon d\'octobre', Carbon::now()->subWeek());

        $this->courses(User::factory()->create())
            ->assertSee('2 débriefs')
            ->assertSee('Album')
            ->assertSee(route('sessions.show', ['session' => $passee, 'tab' => 'debriefs']), false)
            // Sans débrief, la ligne ouvre la fiche sur son onglet par défaut.
            ->assertSee(route('sessions.show', $sansRien).'"', false);
    }

    public function test_cancelled_competition_stays_visible_and_marked(): void
    {
        $this->course('Tri annulé', Carbon::now()->addMonth())->forceFill(['cancelled_at' => Carbon::now()])->save();

        $this->courses(User::factory()->create())
            ->assertSee('Tri annulé')
            ->assertSee('Annulée');
    }

    public function test_category_filter_applies_and_period_controls_are_hidden(): void
    {
        $benj = Category::create(['label' => 'Benjamins', 'age_min' => 10, 'age_max' => 11, 'sort_order' => 1]);
        $adultes = Category::create(['label' => 'Adultes', 'age_min' => 18, 'age_max' => 99, 'sort_order' => 2]);
        $this->course('Course jeunes', Carbon::now()->addMonth())->categories()->sync([$benj->id]);
        $this->course('Course adultes', Carbon::now()->addMonth())->categories()->sync([$adultes->id]);

        $athlete = User::factory()->create();
        $athlete->categories()->attach($adultes->id, ['is_primary' => true]);

        $this->courses($athlete)
            ->assertSee('Course adultes')
            ->assertDontSee('Course jeunes')
            ->assertSee('Les compétitions du club')
            ->assertDontSee('aria-label="Précédent"', false)
            ->assertDontSee('Compét.');
    }

    public function test_courses_view_is_reachable_by_url(): void
    {
        $this->course('Cross de novembre', Carbon::now()->addMonth());

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['view' => 'courses']))
            ->assertOk()
            ->assertSee('Cross de novembre');
    }
}
