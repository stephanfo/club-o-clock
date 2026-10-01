<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Livewire\Planning;
use App\Models\Debrief;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * « Côté courses » (§4.12.5, #104) : l'accueil liste les compétitions débriefées depuis moins de
 * 15 jours, la plus récemment débriefée en tête ; les cartes du planning portent le nombre de
 * débriefs actifs.
 */
class HomeCoteCoursesTest extends TestCase
{
    use RefreshDatabase;

    private function competition(string $title, Carbon $start): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'competition', 'title' => $title,
            'start_at' => $start, 'duration_min' => 120, 'created_by' => $coach->id,
        ]);
    }

    private function debrief(Session $s, string $prenom, Carbon $publie, bool $archive = false): Debrief
    {
        $auteur = User::factory()->create(['first_name' => $prenom]);
        $d = Debrief::create([
            'session_id' => $s->id, 'author_id' => $auteur->id, 'content_markdown' => 'Super course',
            'archived_at' => $archive ? Carbon::now() : null,
        ]);
        $d->forceFill(['created_at' => $publie])->save();

        return $d;
    }

    public function test_lists_recent_debriefs_most_recent_first_with_authors(): void
    {
        $ancienne = $this->competition('Tri de Dijon', Carbon::now()->subDays(10));
        $recente = $this->competition('Duathlon de Beaune', Carbon::now()->subDays(3));
        $this->debrief($ancienne, 'Julie', Carbon::now()->subDay());
        $this->debrief($recente, 'Marc', Carbon::now()->subDays(2));
        $this->debrief($recente, 'Léa', Carbon::now()->subDays(2));

        $this->actingAs(User::factory()->create());
        Livewire::test(Home::class)
            ->assertSee('Côté courses')
            // Dijon a le débrief le plus récent (hier) : il passe devant Beaune.
            ->assertSeeInOrder(['Tri de Dijon', '1 débrief', 'Julie', 'Duathlon de Beaune', '2 débriefs'])
            ->assertSee('Marc, Léa')
            ->assertSee(route('sessions.show', ['session' => $ancienne, 'tab' => 'debriefs']), false);
    }

    public function test_excludes_debriefs_older_than_fifteen_days_and_archived(): void
    {
        $visible = $this->competition('Course visible', Carbon::now()->subDays(20));
        $vieille = $this->competition('Course trop ancienne', Carbon::now()->subDays(30));
        $archivee = $this->competition('Course archivée', Carbon::now()->subDays(5));
        $this->debrief($visible, 'Paul', Carbon::now()->subDays(14));
        $this->debrief($visible, 'Ancien', Carbon::now()->subDays(16));
        $this->debrief($vieille, 'Zoé', Carbon::now()->subDays(16));
        $this->debrief($archivee, 'Tom', Carbon::now()->subDay(), archive: true);

        $this->actingAs(User::factory()->create());
        Livewire::test(Home::class)
            ->assertSee('Course visible')
            ->assertSee('Paul')
            // Seul le débrief de moins de 15 jours compte, sur la ligne comme dans les prénoms.
            ->assertSee('1 débrief')
            ->assertDontSee('Ancien')
            ->assertDontSee('Course trop ancienne')
            ->assertDontSee('Course archivée');
    }

    public function test_block_hidden_when_no_recent_debrief(): void
    {
        $s = $this->competition('Course oubliée', Carbon::now()->subDays(40));
        $this->debrief($s, 'Zoé', Carbon::now()->subDays(30));

        $this->actingAs(User::factory()->create());
        Livewire::test(Home::class)
            ->assertSee('Bonjour')
            ->assertDontSee('Côté courses');
    }

    public function test_planning_cards_show_active_debrief_count(): void
    {
        $s = $this->competition('Course du planning', Carbon::now()->addHour());
        $this->debrief($s, 'Paul', Carbon::now());
        $this->debrief($s, 'Léa', Carbon::now());
        $this->debrief($s, 'Tom', Carbon::now(), archive: true);
        $sans = $this->competition('Course sans débrief', Carbon::now()->addHours(2));

        $this->actingAs(User::factory()->create());
        Livewire::test(Planning::class)
            ->assertSee('Course du planning')
            ->assertSee('Course sans débrief')
            ->assertSee('2 débriefs')
            ->assertDontSee('3 débriefs')
            ->assertDontSee('1 débrief');
    }
}
