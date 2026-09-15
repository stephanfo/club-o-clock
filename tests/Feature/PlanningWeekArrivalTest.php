<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #69 — la vue Semaine mobile s'ouvrait en haut de liste, donc sur lundi, même un samedi. Le
 * serveur désigne le groupe de jour sur lequel positionner la liste à l'arrivée (attribut
 * `data-arrivee`) ; le navigateur ne fait que défiler jusqu'à lui. Tout le choix vit donc ici,
 * là où l'horloge se fige.
 */
class PlanningWeekArrivalTest extends TestCase
{
    use RefreshDatabase;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = ClubSettings::current()->timezone;
        // Jeudi 17 septembre 2026, 10 h heure club : milieu de semaine, lundi→mercredi passés.
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:00', $this->tz));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Séance posée à une date/heure CLUB (start_at est stocké en UTC). */
    private function sessionOn(string $local, string $title): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => Carbon::parse($local, $this->tz)->utc(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
    }

    private function planning()
    {
        return Livewire::actingAs(User::factory()->create())->test(Planning::class);
    }

    public function test_current_week_opens_on_today_when_today_has_sessions(): void
    {
        $this->sessionOn('2026-09-14 18:00', 'Fractionné lundi');
        $this->sessionOn('2026-09-17 18:00', 'Natation jeudi');
        $this->sessionOn('2026-09-19 09:00', 'Sortie samedi');

        $html = $this->planning()->assertViewHas('arrivalDay', '2026-09-17')->html();

        // Contrôle positif : les jours antérieurs restent rendus au-dessus, rien n'est masqué.
        $this->assertStringContainsString('Fractionné lundi', $html);
        $this->assertStringContainsString('wire:key="daygroup-2026-09-14"', $html);

        // Un seul groupe désigné, et c'est celui du jour.
        $this->assertSame(1, substr_count($html, 'data-arrivee'));
        $this->assertMatchesRegularExpression('/wire:key="daygroup-2026-09-17"\s+data-arrivee/', $html);
    }

    public function test_session_already_over_today_still_targets_today(): void
    {
        // Jour courant = le jour, pas « la prochaine séance » : une séance de 7 h reste celle du
        // jour, et son en-tête porte la chip « Aujourd'hui ».
        $this->sessionOn('2026-09-17 07:00', 'Natation matin');
        $this->sessionOn('2026-09-18 18:00', 'Course vendredi');

        $this->planning()->assertViewHas('arrivalDay', '2026-09-17');
    }

    public function test_empty_today_targets_the_next_day_with_sessions(): void
    {
        $this->sessionOn('2026-09-15 18:00', 'Vélo mardi');
        $this->sessionOn('2026-09-19 09:00', 'Sortie samedi');
        $this->sessionOn('2026-09-20 09:00', 'Longue dimanche');

        $html = $this->planning()->assertViewHas('arrivalDay', '2026-09-19')->html();

        // Ni le mardi passé (antérieur), ni le dimanche (pas le premier) : le samedi.
        $this->assertStringContainsString('Vélo mardi', $html);
        $this->assertSame(1, substr_count($html, 'data-arrivee'));
        $this->assertMatchesRegularExpression('/wire:key="daygroup-2026-09-19"\s+data-arrivee/', $html);
    }

    public function test_no_session_left_this_week_stays_at_the_top(): void
    {
        $this->sessionOn('2026-09-14 18:00', 'Fractionné lundi');

        $html = $this->planning()->assertViewHas('arrivalDay', null)->html();

        // Contrôle positif : la semaine a bien une séance, simplement passée.
        $this->assertStringContainsString('Fractionné lundi', $html);
        $this->assertStringNotContainsString('data-arrivee', $html);
    }

    public function test_another_week_stays_at_the_top(): void
    {
        $this->sessionOn('2026-09-24 18:00', 'Natation jeudi prochain');

        $html = $this->planning()->set('anchor', '2026-09-24')->assertViewHas('arrivalDay', null)->html();

        $this->assertStringContainsString('Natation jeudi prochain', $html);
        $this->assertStringNotContainsString('data-arrivee', $html);
    }

    public function test_day_and_month_views_designate_nothing(): void
    {
        $this->sessionOn('2026-09-17 18:00', 'Natation jeudi');

        $this->planning()->set('view', 'day')->assertSee('Natation jeudi')->assertViewHas('arrivalDay', null);
        $this->planning()->set('view', 'month')->assertViewHas('arrivalDay', null);
    }
}
