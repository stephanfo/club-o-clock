<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Session;
use App\Models\User;
use App\Services\JournalExportService;
use App\Services\JournalService;
use App\Support\Logging\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;

// Journaux Audit/Activity J6.7 (PRD §4.18.5) : consultation filtrée fusionnée + export XLSX.
class JournalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12)); // saison 2025-2026 (sept→août)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function journal(): JournalService
    {
        return app(JournalService::class);
    }

    /** @return array<string,mixed> */
    private function allFilters(string $source = 'all'): array
    {
        return ['source' => $source, 'from' => null, 'to' => Carbon::now()];
    }

    private function makeSession(string $title = 'Natation seuil'): Session
    {
        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => Carbon::now()->subDays(2), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    public function test_all_merges_both_journals_desc(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'actor_role' => 'coach',
            'session_id' => $s->id, 'created_at' => Carbon::now()->subHours(2)]);
        ActivityLogger::system('auto_promoted_capacity', ['session_id' => $s->id, 'user_id' => User::factory()->create()->id]);

        $page = $this->journal()->page($this->allFilters(), 25);

        $this->assertSame(2, $page['total']);
        $this->assertSame('activity', $page['rows'][0]['source']); // plus récent en tête
        $this->assertSame('audit', $page['rows'][1]['source']);
    }

    public function test_source_filter_isolates_one_journal(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);
        ActivityLogger::record('registration_created', User::factory()->create(), ['session_id' => $s->id]);

        $this->assertSame(1, $this->journal()->page($this->allFilters('audit'), 25)['total']);
        $this->assertSame(1, $this->journal()->page($this->allFilters('activity'), 25)['total']);
    }

    public function test_actor_and_action_filters(): void
    {
        $a = User::factory()->coach()->create();
        $b = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $a->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $a->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $b->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);

        $byActor = $this->journal()->page(['source' => 'audit', 'actor_id' => $a->id, 'to' => Carbon::now()], 25);
        $this->assertSame(2, $byActor['total']);

        $byAction = $this->journal()->page(['source' => 'audit', 'actions' => ['override_quota'], 'to' => Carbon::now()], 25);
        $this->assertSame(2, $byAction['total']); // override des 2 acteurs
    }

    public function test_session_filter(): void
    {
        $coach = User::factory()->coach()->create();
        $s1 = $this->makeSession('Séance A');
        $s2 = $this->makeSession('Séance B');
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s1->id, 'created_at' => Carbon::now()]);
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s2->id, 'created_at' => Carbon::now()]);

        $this->assertSame(1, $this->journal()->page(['source' => 'all', 'session_id' => $s1->id, 'to' => Carbon::now()], 25)['total']);
    }

    public function test_target_type_filter_excludes_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        AuditLog::create(['action' => 'role_changed', 'actor_id' => $admin->id, 'target_type' => User::class, 'target_id' => $target->id, 'created_at' => Carbon::now()]);
        ActivityLogger::record('registration_created', User::factory()->create(), ['session_id' => $this->makeSession()->id]);

        // Filtre type de cible → seul l'audit (l'activity n'a pas de cible polymorphe).
        $page = $this->journal()->page(['source' => 'all', 'target_type' => User::class, 'to' => Carbon::now()], 25);
        $this->assertSame(1, $page['total']);
        $this->assertSame('audit', $page['rows'][0]['source']);
    }

    public function test_period_window_excludes_old_entries(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()->subDays(5)]);
        AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()->subDays(40)]);

        $p = $this->journal()->resolvePeriod('30d');
        $page = $this->journal()->page(['source' => 'audit', 'from' => $p['from'], 'to' => $p['to']], 25);

        $this->assertSame(1, $page['total']); // l'entrée à J-40 est hors fenêtre 30 j
    }

    public function test_actor_name_resolution_system_and_anonymized(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Élise', 'last_name' => 'Dubois']);
        $tomb = User::factory()->create(['first_name' => 'Compte', 'last_name' => 'supprimé', 'anonymized_at' => Carbon::now()]);
        $s = $this->makeSession();

        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'actor_role' => 'athlete,coach', 'session_id' => $s->id, 'created_at' => Carbon::now()->subMinutes(1)]);
        AuditLog::create(['action' => 'account_deletion_requested', 'actor_id' => $tomb->id, 'session_id' => $s->id, 'created_at' => Carbon::now()->subMinutes(2)]);
        ActivityLogger::system('auto_promoted_capacity', ['session_id' => $s->id, 'user_id' => $coach->id]);

        $rows = collect($this->journal()->page($this->allFilters(), 25)['rows'])->keyBy('action');

        $this->assertSame('Élise Dubois', $rows['override_quota']['actor']);
        $this->assertSame('coach', $rows['override_quota']['actor_role']); // snapshot réduit au rôle le plus fort
        $this->assertSame('Compte supprimé', $rows['account_deletion_requested']['actor']); // tombstone respecté
        $this->assertSame('Système', $rows['auto_promoted_capacity']['actor']);
    }

    public function test_target_label_user_vs_other_type(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['first_name' => 'Hugo', 'last_name' => 'Fournier']);
        $cat = Category::create(['label' => 'U15', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 1]);

        AuditLog::create(['action' => 'role_changed', 'actor_id' => $admin->id, 'target_type' => User::class, 'target_id' => $target->id, 'created_at' => Carbon::now()->subMinute()]);
        AuditLog::create(['action' => 'category_archived', 'actor_id' => $admin->id, 'target_type' => Category::class, 'target_id' => $cat->id, 'created_at' => Carbon::now()->subMinutes(2)]);

        $rows = collect($this->journal()->page($this->allFilters('audit'), 25)['rows'])->keyBy('action');

        $this->assertSame('Hugo Fournier', $rows['role_changed']['target']);
        $this->assertSame('Category #'.$cat->id, $rows['category_archived']['target']);
    }

    public function test_autocomplete_requires_two_chars(): void
    {
        User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Vidal']);

        $this->assertSame([], $this->journal()->actorSuggestions('C')); // < 2 caractères
        $this->assertCount(1, $this->journal()->actorSuggestions('Cam'));
        $this->assertCount(1, $this->journal()->actorSuggestions('Vidal'));
    }

    public function test_available_actions_grouped_by_source(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);
        ActivityLogger::record('registration_created', $coach, ['session_id' => $s->id]);

        $opts = $this->journal()->availableActions();

        $this->assertContains('override_quota', $opts['audit']);
        $this->assertContains('registration_created', $opts['activity']);
        $this->assertNotContains('override_quota', $opts['activity']);
    }

    public function test_find_returns_single_decorated_row(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Marc', 'last_name' => 'Simon']);
        $s = $this->makeSession();
        $log = AuditLog::create(['action' => 'cancel_session', 'actor_id' => $coach->id, 'actor_role' => 'coach', 'session_id' => $s->id, 'motif' => 'Piscine fermée', 'created_at' => Carbon::now()]);

        $row = $this->journal()->find('audit', $log->id);

        $this->assertSame('Marc Simon', $row['actor']);
        $this->assertSame('Piscine fermée', $row['motif']);
        $this->assertNull($this->journal()->find('audit', 99999));
    }

    public function test_export_single_sheet_with_source_column(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id, 'created_at' => Carbon::now()]);

        $book = app(JournalExportService::class)->build($this->allFilters());

        $this->assertSame(1, $book->getSheetCount());
        $this->assertSame('Journaux', $book->getSheet(0)->getTitle());
        $this->assertSame('Source', $book->getActiveSheet()->getCell('B1')->getValue());
        $this->assertSame('audit', $book->getActiveSheet()->getCell('B2')->getValue());
    }

    public function test_export_dates_are_in_club_timezone(): void
    {
        // Audit figé à 12:00 UTC (app.timezone) = 14:00 heure club (Europe/Paris, UTC+2 en juin).
        $coach = User::factory()->coach()->create();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id,
            'created_at' => Carbon::create(2026, 6, 20, 12)]);

        $cell = app(JournalExportService::class)->build($this->allFilters())->getActiveSheet()->getCell('A2'); // colonne Date

        $this->assertSame('20/06/2026 14:00', $cell->getValue());
    }

    public function test_export_neutralizes_formula_injection_in_motif(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->makeSession();
        AuditLog::create(['action' => 'override_quota', 'actor_id' => $coach->id, 'session_id' => $s->id,
            'motif' => '=SUM(99)', 'created_at' => Carbon::now()]);

        $cell = app(JournalExportService::class)->build($this->allFilters())->getActiveSheet()->getCell('H2'); // colonne Motif

        $this->assertSame('=SUM(99)', $cell->getValue());
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }
}
