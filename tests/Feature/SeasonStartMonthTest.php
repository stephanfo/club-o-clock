<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Services\JournalService;
use App\Services\StatsService;
use App\Support\AgeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Revue open source 2026-08-08, constat n°5 : « Mois de bascule de saison » était réglable en
// admin (§4.17) mais ignoré partout où la saison est calculée. Un club réglant janvier voyait la
// bannière de rentrée se déclencher en janvier, puis le recalcul des catégories évaluer l'âge sur
// la saison sept→août — en contradiction avec la bascule que l'application venait de proposer.
class SeasonStartMonthTest extends TestCase
{
    use RefreshDatabase;

    private function setStartMonth(int $month): void
    {
        ClubSettings::current()->update(['season_start_month' => $month]);
        ClubSettings::flushCache();
    }

    /** Référence par défaut : saison sept→août, âge évalué au 31 août de fin de saison. */
    public function test_default_september_season_is_unchanged(): void
    {
        $this->setStartMonth(9);

        // Né le 1er janvier 2010 ; saison démarrée en sept. 2025 → référence 31 août 2026 → 16 ans.
        $this->assertSame(16, AgeCategory::seasonAge(
            Carbon::create(2010, 1, 1),
            Carbon::create(2025, 10, 1),
        ));
    }

    /** Le cœur du constat : en saison janv→déc, la référence devient le 31 décembre. */
    public function test_january_season_shifts_the_reference_date(): void
    {
        $this->setStartMonth(1);

        // Saison 2025 (janv→déc) → référence 31 déc. 2025 → 15 ans, et non 16.
        $this->assertSame(15, AgeCategory::seasonAge(
            Carbon::create(2010, 1, 1),
            Carbon::create(2025, 10, 1),
        ));
    }

    /**
     * La MINORITÉ, elle, ne suit plus la saison (carnet de retours terrain, 2026-09-04).
     *
     * Le mois de bascule déplace la référence de la catégorie sportive — c'est son rôle — mais il
     * n'a aucune prise sur un fait juridique. Adosser la tutelle à l'âge de saison déclarait majeur
     * un adhérent qui ne l'était pas, et jusqu'à douze mois d'avance selon le réglage du club.
     */
    public function test_legal_minority_ignores_the_configured_season(): void
    {
        $dob = Carbon::create(2008, 11, 15);
        $on = Carbon::create(2026, 3, 1);

        // La référence sportive, elle, bouge bien : 31/08/2026 → 17 ans, 31/12/2026 → 18 ans.
        $this->setStartMonth(9);
        $this->assertSame(17, AgeCategory::seasonAge($dob, $on));
        $septembre = AgeCategory::isLegallyMinor($dob, $on);

        $this->setStartMonth(1);
        $this->assertSame(18, AgeCategory::seasonAge($dob, $on));
        $janvier = AgeCategory::isLegallyMinor($dob, $on);

        // Au 01/03/2026, il a 17 ans révolus : mineur, quel que soit le mois de bascule.
        $this->assertTrue($septembre);
        $this->assertTrue($janvier, 'Le réglage club ne peut pas rendre quelqu\'un majeur avant l\'heure.');
    }

    /** Le paramètre explicite l'emporte sur le réglage club (calcul testable sans base). */
    public function test_explicit_start_month_overrides_the_club_setting(): void
    {
        $this->setStartMonth(9);

        $this->assertSame(15, AgeCategory::seasonAge(
            Carbon::create(2010, 1, 1),
            Carbon::create(2025, 10, 1),
            startMonth: 1,
        ));
    }

    /** Les journaux : la période « saison » démarre au mois réglé, pas en septembre. */
    public function test_journal_season_period_uses_the_configured_month(): void
    {
        $this->setStartMonth(1);
        Carbon::setTestNow(Carbon::create(2026, 3, 15, 12));

        $from = app(JournalService::class)->resolvePeriod('season')['from'];

        $this->assertSame(1, $from?->month, 'La saison doit démarrer en janvier.');
        $this->assertSame(2026, $from?->year);

        Carbon::setTestNow();
    }

    /** Le dashboard : même règle pour les indicateurs « depuis le début de saison ». */
    public function test_stats_season_period_uses_the_configured_month(): void
    {
        $this->setStartMonth(1);
        Carbon::setTestNow(Carbon::create(2026, 3, 15, 12));

        $from = app(StatsService::class)->resolvePeriod('season')['from'];

        $this->assertSame(1, $from->month);
        $this->assertSame(2026, $from->year);

        Carbon::setTestNow();
    }
}
