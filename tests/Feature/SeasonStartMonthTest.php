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

    /** Le statut mineur suit la même référence (§4.2) — il conditionne la tutelle parentale. */
    public function test_minor_status_follows_the_configured_season(): void
    {
        $dob = Carbon::create(2008, 11, 15);
        $on = Carbon::create(2026, 3, 1);

        $this->setStartMonth(9);
        $septemberSeason = AgeCategory::isMinor($dob, $on);

        $this->setStartMonth(1);
        $januarySeason = AgeCategory::isMinor($dob, $on);

        // Saison sept→août : référence 31/08/2026 → 17 ans, mineur.
        // Saison janv→déc  : référence 31/12/2026 → 18 ans, majeur.
        $this->assertTrue($septemberSeason, 'Saison sept→août : encore mineur à la référence.');
        $this->assertFalse($januarySeason, 'Saison janv→déc : la référence plus tardive le rend majeur.');
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
