<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\SessionTemplate;
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

    /** Rattache une séance à un modèle sans passer par le générateur. */
    private function fromTemplate(Session $s, SessionTemplate $t): Session
    {
        $s->forceFill(['source_template_id' => $t->id])->save();

        return $s;
    }

    /** Journalise une désinscription comme RegistrationService::unregister, à l'instant donné. */
    private function unregisterLog(Session $s, Carbon $at, string $action = 'desinscription'): void
    {
        $u = User::factory()->create();
        ActivityLog::create(['actor_id' => $u->id, 'action' => $action, 'user_id' => $u->id,
            'session_id' => $s->id, 'resulting_status' => 'cancelled', 'created_at' => $at]);
    }

    public function test_slots_aggregate_sessions_of_a_template_and_group_one_offs_by_discipline(): void
    {
        $jeudi = SessionTemplate::factory()->archived()->create(['label' => 'Natation jeudi', 'discipline_id' => $this->swim->id,
            'day_of_week' => 4, 'start_time_of_day' => '18:30:00']);

        $a = $this->fromTemplate($this->training($this->swim, 10, Carbon::now()->subDays(14)), $jeudi);
        $b = $this->fromTemplate($this->training($this->swim, 10, Carbon::now()->subDays(7)), $jeudi);
        foreach (User::factory()->count(6)->create() as $u) {
            $this->participate($a, $u);
        }
        foreach (User::factory()->count(2)->create() as $u) {
            $this->participate($b, $u);
        }
        $a->coaches()->attach(User::factory()->coach()->create()->id); // $b reste sans encadrement

        // Ponctuelle vélo, sans capacité, tenue par un intervenant extérieur : encadrée.
        $p = $this->training($this->bike, null, Carbon::now()->subDays(3));
        $p->forceFill(['external_staff_label' => 'Moniteur prestataire'])->save();
        $this->participate($p, User::factory()->create());

        // Exclues : annulée, future, inscription non `participating`.
        $c = $this->fromTemplate($this->training($this->swim, 10, Carbon::now()->subDays(1)), $jeudi);
        $c->forceFill(['cancelled_at' => Carbon::now()])->save();
        $this->fromTemplate($this->training($this->swim, 10, Carbon::now()->addDays(2)), $jeudi);
        $this->participate($b, User::factory()->create(), 'waitlist', ['waitlist_reason' => 'capacity', 'waitlist_position' => 1]);

        $slots = $this->stats()->slots($this->season());

        $this->assertCount(2, $slots);
        $this->assertSame('Natation jeudi', $slots[0]['label']); // moyenne 4 > 1 : en tête
        $this->assertSame('jeudi', $slots[0]['day']);
        $this->assertSame('18:30', $slots[0]['time']);
        $this->assertSame(2, $slots[0]['sessions']);
        $this->assertSame(4.0, $slots[0]['avg']);
        $this->assertSame([2, 6], [$slots[0]['min'], $slots[0]['max']]);
        $this->assertSame(40, $slots[0]['fill']); // 8 / 20
        $this->assertSame(50, $slots[0]['unstaffed']);

        $this->assertSame('Séances ponctuelles', $slots[1]['label']);
        $this->assertSame($this->bike->id, $slots[1]['discipline']->id);
        $this->assertNull($slots[1]['day']);
        $this->assertNull($slots[1]['fill']); // sans capacité : « — », pas 0 %
        $this->assertSame(0, $slots[1]['unstaffed']);
    }

    public function test_slots_apply_discipline_and_category_filters(): void
    {
        $cat = Category::create(['label' => 'Jeunes', 'age_min' => 10, 'age_max' => 17, 'sort_order' => 1]);
        $swim = $this->training($this->swim, 10, Carbon::now()->subDays(2));
        $swim->categories()->attach($cat->id);
        $this->training($this->bike, 10, Carbon::now()->subDays(2));

        $season = $this->season();
        $this->assertCount(1, $this->stats()->slots(['discipline_id' => $this->bike->id] + $season));
        $this->assertSame($this->bike->id, $this->stats()->slots(['discipline_id' => $this->bike->id] + $season)[0]['discipline']->id);
        $this->assertSame($this->swim->id, $this->stats()->slots(['category_id' => $cat->id] + $season)[0]['discipline']->id);
        $this->assertCount(1, $this->stats()->slots(['category_id' => $cat->id] + $season));
    }

    public function test_unregistrations_count_late_ones_from_the_activity_log(): void
    {
        $s = $this->training($this->swim, 10, Carbon::now()->subDays(2));
        $this->unregisterLog($s, $s->start_at->copy()->subHours(23));          // tardive
        $this->unregisterLog($s, $s->start_at->copy()->subHours(25), 'desinscription_for_other'); // à temps
        $this->unregisterLog($s, $s->start_at->copy()->subDays(4));

        // Séance annulée : ses désinscriptions n'entrent pas, même tardives.
        $cancelled = $this->training($this->bike, 10, Carbon::now()->subDays(3));
        $cancelled->forceFill(['cancelled_at' => Carbon::now()->subDays(4)])->save();
        $this->unregisterLog($cancelled, $cancelled->start_at->copy()->subHour());

        // Autre action du journal sur la même séance : ignorée.
        ActivityLog::create(['action' => 'inscription', 'session_id' => $s->id, 'created_at' => $s->start_at->copy()->subHour()]);

        $u = $this->stats()->unregistrations($this->season());

        $this->assertSame(3, $u['total']);
        $this->assertSame(1, $u['late']);
        $this->assertSame(33, $u['late_pct']);
        $this->assertCount(1, $u['rows']);
        $this->assertSame($this->swim->id, $u['rows'][0]['discipline']->id);

        $this->assertSame(0, $this->stats()->unregistrations(['discipline_id' => $this->bike->id] + $this->season())['total']);
        $this->assertNull($this->stats()->unregistrations(['discipline_id' => $this->bike->id] + $this->season())['late_pct']);
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

    // ── Fréquentation hebdomadaire (§4.16.2, bloc 4 — #160) ──

    /** Ligne de la semaine (heure club) qui contient $day. */
    private function weekOf(array $weekly, Carbon $day): array
    {
        $monday = $day->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        return collect($weekly)->first(fn ($w) => $w['start']->format('Y-m-d') === $monday);
    }

    public function test_weekly_attendance_counts_at_session_date_not_registration_date(): void
    {
        // Inscription prise en septembre pour une séance de mars : elle compte en mars.
        $s = $this->training($this->swim, null, Carbon::create(2026, 3, 11, 18));
        $this->participate($s, User::factory()->create())->forceFill(['created_at' => Carbon::create(2025, 9, 10)])->save();

        $weekly = $this->stats()->weeklyAttendance($this->season());

        $this->assertSame(1, $this->weekOf($weekly, Carbon::create(2026, 3, 11))['participations']);
        $this->assertSame(0, $this->weekOf($weekly, Carbon::create(2025, 9, 10))['participations']);
    }

    public function test_weekly_attendance_counts_participations_and_distinct_athletes(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $mardi = $this->training($this->swim, null, Carbon::create(2026, 3, 10, 18));
        $jeudi = $this->training($this->bike, null, Carbon::create(2026, 3, 12, 18));
        $this->participate($mardi, $a);
        $this->participate($mardi, $b);
        $this->participate($jeudi, $a);

        $w = $this->weekOf($this->stats()->weeklyAttendance($this->season()), Carbon::create(2026, 3, 10));

        $this->assertSame(2, $w['sessions']);
        $this->assertSame(3, $w['participations']);
        $this->assertSame(2, $w['athletes']); // a compté une fois
    }

    public function test_weekly_attendance_excludes_non_participations(): void
    {
        $day = Carbon::create(2026, 3, 11, 18);
        $ok = $this->training($this->swim, null, $day);
        $this->participate($ok, User::factory()->create()); // contrôle positif
        $this->participate($ok, User::factory()->create(), 'waitlist', ['waitlist_reason' => 'capacity']);
        $this->participate($ok, User::factory()->create(), 'cancelled');

        $annulee = $this->training($this->swim, null, $day);
        $annulee->forceFill(['cancelled_at' => Carbon::create(2026, 3, 1)])->save();
        $this->participate($annulee, User::factory()->create());

        $competition = Session::create(['kind' => 'competition', 'title' => 'Tri', 'start_at' => $day,
            'duration_min' => 60, 'created_by' => User::factory()->coach()->create()->id]);
        $this->participate($competition, User::factory()->create());

        $w = $this->weekOf($this->stats()->weeklyAttendance($this->season()), $day);

        $this->assertSame(1, $w['sessions']);
        $this->assertSame(1, $w['participations']);
    }

    public function test_weekly_attendance_shows_empty_weeks_and_flags_the_current_one(): void
    {
        $this->participate($this->training($this->swim, null, Carbon::create(2026, 3, 11, 18)), User::factory()->create());
        // Séance plus tard aujourd'hui (samedi 20 juin, 12 h) : pas encore tenue, donc hors comptes.
        $this->participate($this->training($this->swim, null, Carbon::now()->addHours(3)), User::factory()->create());

        $weekly = $this->stats()->weeklyAttendance($this->season());

        // Vacances : la semaine existe, à zéro.
        $vacances = $this->weekOf($weekly, Carbon::create(2026, 4, 15));
        $this->assertSame(0, $vacances['sessions']);
        $this->assertFalse($vacances['partial']);

        // Dernière semaine = semaine en cours, partielle, sans la séance de cet après-midi.
        $last = end($weekly);
        $this->assertSame('2026-06-15', $last['start']->format('Y-m-d'));
        $this->assertTrue($last['partial']);
        $this->assertSame(0, $last['participations']);
    }

    public function test_weekly_attendance_follows_the_club_week_across_dst(): void
    {
        // Lundi 30 mars 2026, 0 h 30 à Paris (heure d'été depuis la veille) = dimanche 29, 22 h 30 UTC :
        // la séance appartient à la semaine locale du 30 mars. Un regroupement par semaine UTC la
        // rangerait dans celle du 23.
        $s = $this->training($this->swim, null, Carbon::create(2026, 3, 30, 0, 30, 0, 'Europe/Paris')->utc());
        $this->participate($s, User::factory()->create());

        $weekly = $this->stats()->weeklyAttendance($this->season());

        $this->assertSame(1, $this->weekOf($weekly, Carbon::create(2026, 3, 30))['participations']);
        $this->assertSame(0, $this->weekOf($weekly, Carbon::create(2026, 3, 23))['participations']);
    }

    public function test_headline_practitioners_weekly_average_and_competitors(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        User::factory()->create(); // compte athlète sans séance : au dénominateur seulement

        // Deux semaines avec séances (3 puis 1 participations) et des semaines vides entre elles.
        $s1 = $this->training($this->swim, null, Carbon::create(2026, 3, 10, 18));
        $s2 = $this->training($this->swim, null, Carbon::create(2026, 3, 12, 18));
        $s3 = $this->training($this->swim, null, Carbon::create(2026, 5, 12, 18));
        $this->participate($s1, $a);
        $this->participate($s1, $b);
        $this->participate($s2, $a);
        $this->participate($s3, $b);

        // Deux compétitions, b inscrit aux deux : un seul compétiteur pour lui.
        foreach (['Tri M', 'Tri L'] as $t) {
            $c = Session::create(['kind' => 'competition', 'title' => $t, 'start_at' => Carbon::create(2026, 5, 3, 9),
                'duration_min' => 120, 'created_by' => User::factory()->coach()->create()->id]);
            $this->participate($c, $b);
        }

        $h = $this->stats()->headline($this->season());

        $this->assertSame(3, $h['active']);
        $this->assertSame(2, $h['practitioners']);
        $this->assertSame(2, $h['weekly_avg']); // (3 + 1) / 2 semaines avec séances
        $this->assertSame(1, $h['competitors']);
        $this->assertSame(2, $h['competitions']);
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

    public function test_export_has_slot_and_unregistration_sheets(): void
    {
        $s = $this->training($this->swim, 5, Carbon::now()->subDays(3));
        $this->participate($s, User::factory()->create());
        $this->unregisterLog($s, $s->start_at->copy()->subHours(2));

        $book = app(StatsExportService::class)->build($this->season(), $this->stats()->resolvePeriod('season'));

        $this->assertNull($book->getSheetByName('Top séances'));
        $this->assertSame('Séances ponctuelles', $book->getSheetByName('Créneaux')->getCell('A2')->getValue());
        $this->assertSame('Natation', $book->getSheetByName('Désinscriptions')->getCell('A2')->getValue());
        $this->assertSame('Total', $book->getSheetByName('Désinscriptions')->getCell('A3')->getValue());
        $this->assertSame(100, $book->getSheetByName('Désinscriptions')->getCell('D3')->getValue());
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
