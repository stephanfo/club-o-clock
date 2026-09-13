<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Models\AperoFlag;
use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accueil — lisibilité des dates (#58).
 *
 * La section « Apéro à venir » et le héros « Prochaine · » n'affichaient que le jour de SEMAINE
 * (`ddd`), sans quantième. Or aucune des deux listes n'est bornée par une fenêtre temporelle :
 * l'apéro étant typiquement récurrent le même soir, deux entrées à trois semaines d'écart
 * s'affichaient rigoureusement à l'identique. Le format attendu est celui que CLAUDE.md fixe
 * déjà pour une liste dense : `ddd D MMM`.
 */
class HomeDatesTest extends TestCase
{
    use RefreshDatabase;

    private function tz(): string
    {
        // start_at est stocké en UTC et rendu dans le fuseau du club : dériver l'attendu,
        // ne jamais comparer à l'heure littérale saisie.
        return ClubSettings::current()->timezone;
    }

    private function seanceAvecApero(string $titre, Carbon $debut): Session
    {
        $coach = User::factory()->coach()->create();
        $s = Session::create([
            'kind' => 'training', 'title' => $titre,
            'start_at' => $debut, 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
        $u = User::factory()->create();
        $r = Registration::create([
            'session_id' => $s->id, 'user_id' => $u->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);
        AperoFlag::create([
            'session_id' => $s->id, 'user_id' => $u->id,
            'registration_id' => $r->id, 'flagged_at' => Carbon::now(),
        ]);

        return $s;
    }

    /** Le libellé attendu pour une séance, dans le fuseau du club. */
    private function libelle(Session $s): string
    {
        return $s->start_at->copy()->setTimezone($this->tz())->locale('fr')->isoFormat('ddd D MMM');
    }

    public function test_apero_list_shows_day_of_month_so_recurring_evenings_are_distinguishable(): void
    {
        // Deux apéros le MÊME soir de semaine, à trois semaines d'écart : sans quantième, leurs
        // deux lignes sont strictement identiques.
        $proche = $this->seanceAvecApero('Apéro proche', Carbon::now()->addDays(3)->setTime(18, 30));
        $lointain = $this->seanceAvecApero('Apéro lointain', Carbon::now()->addDays(24)->setTime(18, 30));

        $html = Livewire::actingAs(User::factory()->create())->test(Home::class)
            // Contrôle positif apparié : la section liste bien les deux séances — sans quoi les
            // assertions de date ne vaudraient rien sur une liste vide.
            ->assertSee('Apéro proche')
            ->assertSee('Apéro lointain')
            ->html();

        $this->assertStringContainsString($this->libelle($lointain), $html,
            'Le quantième de l\'apéro lointain doit être rendu.');
        $this->assertNotSame($this->libelle($proche), $this->libelle($lointain),
            'Prémisse du test : les deux dates doivent différer une fois le quantième rendu.');
    }

    public function test_hero_next_session_shows_day_of_month_in_both_shells(): void
    {
        // Le héros pointe la prochaine séance DU CLUB, qui peut tomber la semaine suivante :
        // « ven. 18:30 » y est tout aussi trompeur, sur l'élément le plus regardé de l'écran.
        $next = $this->seanceAvecApero('Sortie longue', Carbon::now()->addDays(9)->setTime(9, 0));

        $html = Livewire::actingAs(User::factory()->create())->test(Home::class)
            ->assertViewHas('next', fn ($n) => $n->title === 'Sortie longue')   // contrôle positif
            ->html();

        $attendu = 'Prochaine · '.$this->libelle($next);
        // L'accueil rend DEUX coquilles (mobile et desktop) dans le même DOM : les deux doivent
        // porter le correctif, d'où le comptage plutôt qu'un simple assertSee.
        $this->assertSame(2, substr_count($html, $attendu),
            "Les deux coquilles doivent rendre « {$attendu} ».");
    }
}
