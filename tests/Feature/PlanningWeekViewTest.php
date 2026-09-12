<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Livewire\Planning;
use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vue Semaine du planning (§4.2). Le format mobile empile des groupes de jour dont l'en-tête est
 * collant : ces groupes doivent être identifiés, sinon le morphing Livewire réécrit leurs nœuds
 * texte en place d'une semaine à l'autre et WebKit ne recompose pas l'en-tête sticky (issue #32).
 */
class PlanningWeekViewTest extends TestCase
{
    use RefreshDatabase;

    private function sessionOn(Carbon $at, string $title): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $at, 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
    }

    public function test_each_mobile_day_group_is_keyed_on_its_date(): void
    {
        $monday = Carbon::now()->startOfWeek();
        $tuesday = $monday->copy()->addDay();

        $this->sessionOn($monday->copy()->setTime(18, 0), 'Fractionné');
        $this->sessionOn($tuesday->copy()->setTime(18, 0), 'Sortie longue');

        $athlete = User::factory()->create();
        $html = Livewire::actingAs($athlete)->test(Planning::class)->set('view', 'week')->html();

        // Contrôle positif : les deux jours sont bien rendus (sans quoi l'assertion suivante
        // serait vraie sur une liste vide).
        $this->assertStringContainsString('Fractionné', $html);
        $this->assertStringContainsString('Sortie longue', $html);

        // Chaque groupe porte une clé propre à sa date : le morphing détruit et recrée le bloc
        // au changement de semaine au lieu d'y substituer du texte.
        $this->assertStringContainsString('wire:key="daygroup-'.$monday->toDateString().'"', $html);
        $this->assertStringContainsString('wire:key="daygroup-'.$tuesday->toDateString().'"', $html);
    }

    /**
     * #33 — en vue Semaine mobile, l'en-tête de jour (collant) porte déjà le numéro et le nom du
     * jour. La colonne de gauche des cartes les répétait, avec le numéro en 22 px : le plus gros
     * caractère de la carte accordé à ce qu'elle a de moins informatif. Elle porte désormais la
     * plage horaire — dont l'heure de fin, qui n'existait dans aucune liste.
     */
    public function test_week_mobile_cards_show_the_time_range_instead_of_the_day(): void
    {
        $monday = Carbon::now()->startOfWeek();
        $session = $this->sessionOn($monday->copy()->setTime(18, 30), 'Fractionné');
        $session->update(['duration_min' => 75]);

        $athlete = User::factory()->create();
        $html = Livewire::actingAs($athlete)->test(Planning::class)->set('view', 'week')->html();

        // Les cartes affichent l'heure CLUB (start_at est stocké en UTC) : on compare à ce que la
        // vue rend, pas à ce qu'on a saisi.
        $tz = ClubSettings::current()->timezone;
        $debut = $session->start_at->copy()->setTimezone($tz);
        $fin = $session->endsAt()->copy()->setTimezone($tz);

        // Contrôle positif : la carte est bien rendue, et son heure de début y est.
        $this->assertStringContainsString('Fractionné', $html);
        $this->assertStringContainsString($debut->format('H:i'), $html);
        // L'heure de fin n'apparaissait dans aucune liste — seulement sur la fiche séance.
        $this->assertStringContainsString($fin->format('H:i'), $html);
        // Et le numéro du jour en 22 px, doublon de l'en-tête, a disparu de la carte.
        $this->assertStringNotContainsString('font-size:22px">'.$debut->format('j').'<', $html);
    }

    /**
     * Contrepartie de #33 : partout ailleurs, la date est justement ce qui situe la séance — ces
     * listes ne sont pas groupées par jour. Le troisième état ne vaut QUE pour la vue Semaine.
     */
    public function test_other_lists_keep_the_full_date_column(): void
    {
        // DEUX séances : avec une seule, l'accueil la rend en hero (« Prochaine ») et n'ouvre
        // aucune liste de cartes — le contrôle ne porterait sur rien.
        $premiere = $this->sessionOn(Carbon::now()->addDay()->setTime(18, 30), 'Fractionné');
        $session = $this->sessionOn(Carbon::now()->addDays(2)->setTime(18, 30), 'Sortie longue');
        $athlete = User::factory()->create();
        // L'accueil ne liste que « mes prochaines séances » : il faut y être inscrit·e.
        foreach ([$premiere, $session] as $s) {
            Registration::create([
                'session_id' => $s->id, 'user_id' => $athlete->id,
                'status' => 'participating', 'registered_at' => Carbon::now(),
            ]);
        }

        $html = Livewire::actingAs($athlete)->test(Home::class)->html();

        $jour = $session->start_at->copy()->setTimezone(ClubSettings::current()->timezone)->format('j');
        $this->assertStringContainsString('Sortie longue', $html);
        $this->assertStringContainsString('font-size:22px">'.$jour.'<', $html);
    }
}
