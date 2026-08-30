<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\StatsExportService;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;

// Dashboard stats bureau J6.6 (PRD §4.16.1) : indicateurs de pilotage + filtres période/discipline/catégorie.
class StatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Discipline $swim;

    private Discipline $bike;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12)); // saison 2025-2026 (sept→août)
        $this->swim = Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
        $this->bike = Discipline::create(['label' => 'Vélo', 'sort_order' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stats(): StatsService
    {
        return app(StatsService::class);
    }

    private function season(): array
    {
        $p = $this->stats()->resolvePeriod('season');

        return ['from' => $p['from'], 'to' => $p['to'], 'discipline_id' => null, 'category_id' => null];
    }

    private function training(Discipline $disc, ?int $capacity, Carbon $startAt): Session
    {
        return Session::create([
            'kind' => 'training',
            'title' => 'Séance '.$disc->label,
            'discipline_id' => $disc->id,
            'start_at' => $startAt,
            'duration_min' => 60,
            'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    private function participate(Session $s, User $u, string $status = 'participating', array $extra = []): Registration
    {
        return Registration::create(array_merge([
            'session_id' => $s->id,
            'user_id' => $u->id,
            'status' => $status,
            'registered_at' => Carbon::now(),
        ], $extra));
    }

    public function test_headline_active_fill_competitions_overrides(): void
    {
        User::factory()->count(3)->create(); // 3 athlètes actifs (rôle athlete par défaut)
        User::factory()->create(['athlete_access_suspended' => true]); // exclu
        User::factory()->admin()->create(); // admin pur, pas athlete → exclu

        // Remplissage : capacité 10, 7 inscrits → 70%.
        $s = $this->training($this->swim, 10, Carbon::now()->subDays(5));
        foreach (User::factory()->count(7)->create() as $u) {
            $this->participate($s, $u);
        }

        // 1 compétition dans la fenêtre.
        Session::create(['kind' => 'competition', 'title' => 'Triathlon L', 'discipline_id' => $this->swim->id,
            'start_at' => Carbon::now()->subDays(2), 'duration_min' => 120, 'created_by' => User::factory()->coach()->create()->id]);

        // 1 override quota.
        AuditLog::create(['action' => 'override_quota', 'actor_id' => User::factory()->coach()->create()->id,
            'session_id' => $s->id, 'created_at' => Carbon::now()->subDay()]);

        $h = $this->stats()->headline($this->season());

        $this->assertSame(10, $h['active']); // 3 + 7 inscrits (tous athletes actifs)
        $this->assertSame(70, $h['fill_rate']);
        $this->assertSame(1, $h['competitions']);
        $this->assertSame(1, $h['overrides']);
    }

    public function test_discipline_filter_narrows_fill_rate(): void
    {
        $swimSession = $this->training($this->swim, 10, Carbon::now()->subDays(5));
        foreach (User::factory()->count(5)->create() as $u) {
            $this->participate($swimSession, $u);
        }
        $bikeSession = $this->training($this->bike, 10, Carbon::now()->subDays(4));
        foreach (User::factory()->count(9)->create() as $u) {
            $this->participate($bikeSession, $u);
        }

        $f = ['from' => $this->season()['from'], 'to' => $this->season()['to'], 'discipline_id' => $this->swim->id, 'category_id' => null];

        $this->assertSame(50, $this->stats()->headline($f)['fill_rate']); // natation seule : 5/10
    }

    /**
     * Revue de code — une compétition ne porte jamais de discipline (§4.7) : le filtre discipline
     * du dashboard ne doit pas s'appliquer au compteur « compétitions », sans quoi il retomberait
     * systématiquement à 0 dès qu'une discipline précise est sélectionnée.
     */
    public function test_discipline_filter_does_not_zero_out_the_competition_count(): void
    {
        Session::create(['kind' => 'competition', 'title' => 'Triathlon L',
            'start_at' => Carbon::now()->subDays(2), 'duration_min' => 120, 'created_by' => User::factory()->coach()->create()->id]);

        $f = ['from' => $this->season()['from'], 'to' => $this->season()['to'], 'discipline_id' => $this->swim->id, 'category_id' => null];

        $this->assertSame(1, $this->stats()->headline($f)['competitions']);
    }

    public function test_top_sessions_ranked_by_fill(): void
    {
        $low = $this->training($this->swim, 10, Carbon::now()->subDays(6));
        $this->participate($low, User::factory()->create());
        $high = $this->training($this->bike, 10, Carbon::now()->subDays(6));
        foreach (User::factory()->count(9)->create() as $u) {
            $this->participate($high, $u);
        }

        $top = $this->stats()->topSessions($this->season());

        $this->assertSame(90, $top[0]['fill']); // le plus rempli en tête
        $this->assertSame(10, $top[1]['fill']);
    }

    public function test_waitlist_counts_and_promotion_rate(): void
    {
        // Séance future : la file « vive » ne compte que les séances à venir non annulées.
        $s = $this->training($this->swim, 2, Carbon::now()->addDays(5));
        $this->participate($s, User::factory()->create(), 'waitlist', ['waitlist_reason' => 'capacity', 'waitlist_position' => 1]);
        $this->participate($s, User::factory()->create(), 'waitlist', ['waitlist_reason' => 'quota_exceeded', 'waitlist_position' => 2]);
        // 1 promu dans la fenêtre.
        $this->participate($s, User::factory()->create(), 'participating', ['promoted_at' => Carbon::now()->subDay()]);

        // Waitlist sur séance passée → périmée, exclue du total « vif ».
        $past = $this->training($this->swim, 2, Carbon::now()->subDays(10));
        $this->participate($past, User::factory()->create(), 'waitlist', ['waitlist_reason' => 'capacity', 'waitlist_position' => 1]);

        $w = $this->stats()->waitlist($this->season());

        $this->assertSame(2, $w['total']); // la waitlist périmée n'est pas comptée
        $this->assertSame(1, $w['capacity']);
        $this->assertSame(1, $w['quota']);
        $this->assertSame(33, $w['promotion_rate']); // 1 promu / (1 + 2 en attente)
    }

    public function test_coach_activity_and_future_without_coach(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Élise', 'last_name' => 'Dubois']);

        $s1 = $this->training($this->swim, null, Carbon::now()->subDays(10));
        $s1->coaches()->attach($coach->id);
        $s2 = $this->training($this->bike, null, Carbon::now()->subDays(8));
        $s2->coaches()->attach($coach->id);

        // Séance future sans coach.
        $this->training($this->swim, null, Carbon::now()->addDays(5));

        $ca = $this->stats()->coachActivity($this->season());

        $this->assertCount(1, $ca['rows']);
        $this->assertSame('Élise Dubois', $ca['rows'][0]['coach']);
        $this->assertSame(2, $ca['rows'][0]['total']);
        $this->assertSame(1, $ca['rows'][0]['by_discipline'][$this->swim->id]);
        $this->assertSame(1, $ca['future_without_coach']);
    }

    public function test_monthly_registrations_bucketed_by_month(): void
    {
        $s = $this->training($this->swim, null, Carbon::now()->subDays(3));
        // 2 inscriptions en avril, 1 en juin.
        $this->participate($s, User::factory()->create())->forceFill(['created_at' => Carbon::create(2026, 4, 10)])->save();
        $this->participate($s, User::factory()->create())->forceFill(['created_at' => Carbon::create(2026, 4, 20)])->save();
        $this->participate($s, User::factory()->create())->forceFill(['created_at' => Carbon::create(2026, 6, 5)])->save();

        $monthly = collect($this->stats()->monthlyRegistrations($this->season()));

        $this->assertSame(2, $monthly->firstWhere('label', 'avr')['count']);
        $this->assertSame(1, $monthly->firstWhere('label', 'juin')['count']);
        $this->assertSame(0, $monthly->firstWhere('label', 'mai')['count']);
    }

    public function test_session_outside_period_is_excluded(): void
    {
        // Avant le début de saison (juin 2025) → hors fenêtre « saison en cours ».
        $old = $this->training($this->swim, 10, Carbon::create(2025, 6, 1));
        foreach (User::factory()->count(8)->create() as $u) {
            $this->participate($old, $u);
        }

        $this->assertNull($this->stats()->headline($this->season())['fill_rate']); // aucune séance dans la fenêtre
    }

    public function test_overrides_count_both_forcing_mechanisms(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->training($this->swim, 5, Carbon::now()->subDays(3));
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()->subDay()]);
        AuditLog::create(['action' => 'promote_quota_exceeded', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()->subDay()]);

        $this->assertSame(2, $this->stats()->headline($this->season())['overrides']);
    }

    public function test_export_neutralizes_formula_injection(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->training($this->swim, 5, Carbon::now()->subDays(3));
        // Motif d'override saisi commençant par « = » → ne doit JAMAIS devenir une formule.
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id,
            'motif' => '=SUM(99)', 'created_at' => Carbon::now()->subDay()]);

        $period = $this->stats()->resolvePeriod('season');
        $book = app(StatsExportService::class)->build($this->season(), $period);
        $cell = $book->getSheetByName('Overrides')->getCell('A2');

        $this->assertSame('=SUM(99)', $cell->getValue()); // valeur littérale préservée
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType()); // stockée en texte, pas formule
    }

    public function test_active_members_by_category_for_export(): void
    {
        $senior = Category::create(['label' => 'Sénior', 'age_min' => 18, 'age_max' => 39, 'sort_order' => 1]);
        $u = User::factory()->create();
        $u->categories()->attach($senior->id, ['is_primary' => true]);

        $rows = $this->stats()->activeMembersByCategory($this->season());

        $this->assertSame('Sénior', $rows[0]['label']);
        $this->assertSame(1, $rows[0]['count']);
    }
}
