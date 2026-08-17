<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\TemplateGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Générateur de séances J5 (PRD §4.8) : template → N Session indépendantes, defaultCoachIds,
// sourceTemplateId audit-only, N ActivityLog, relance sans écraser.
class TemplateGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TemplateGenerationService
    {
        return app(TemplateGenerationService::class);
    }

    public function test_occurrences_counts_weekly_dates_in_range(): void
    {
        // Lundis du 2026-09-07 (1er lundi) au 2026-09-28 = 4 lundis.
        $tpl = SessionTemplate::factory()->create(['day_of_week' => 1]);
        $occ = $this->service()->occurrences($tpl, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertCount(4, $occ);
        $this->assertSame('2026-09-07', $occ->first()->toDateString());
        $this->assertSame('2026-09-28', $occ->last()->toDateString());
        $this->assertTrue($occ->every(fn (Carbon $d) => $d->dayOfWeekIso === 1));
    }

    public function test_occurrences_empty_when_no_target_day_in_range(): void
    {
        $tpl = SessionTemplate::factory()->create(['day_of_week' => 3]); // mercredi
        // Plage lun→mar (2026-09-07 lun, 2026-09-08 mar) : aucun mercredi.
        $occ = $this->service()->occurrences($tpl, Carbon::parse('2026-09-07'), Carbon::parse('2026-09-08'));

        $this->assertCount(0, $occ);
    }

    public function test_generate_creates_independent_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id,
            'day_of_week' => 1,
            'start_time_of_day' => '19:30',
            'duration_min' => 90,
            'generation_start_date' => '2026-09-01',
            'generation_end_date' => '2026-09-30',
        ]);

        $created = $this->service()->generate($tpl, $admin);

        $this->assertCount(4, $created);
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());

        $first = $created->first()->fresh();
        $this->assertSame('Natation seuil', $first->title);
        $this->assertSame('training', $first->kind);
        $this->assertSame($admin->id, $first->created_by); // createdBy = admin (§4.8)
        // start_at stocké en UTC ; 19:30 est l'heure LOCALE club (comme l'affichage).
        $tz = ClubSettings::current()->timezone;
        $this->assertSame('19:30', $first->start_at->copy()->setTimezone($tz)->format('H:i'));
        $this->assertSame(90, $first->duration_min);
    }

    public function test_generated_start_at_matches_manual_creation_wall_clock(): void
    {
        // Convention de l'app (cf. SessionForm) : start_at est construit dans le fuseau du club puis
        // converti en UTC par le mutateur du modèle. Une séance générée à 19:00 heure locale doit
        // stocker exactement le même instant qu'une séance créée manuellement à 19:00 le même jour.
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1, 'start_time_of_day' => '19:00',
            'generation_start_date' => '2026-09-07', 'generation_end_date' => '2026-09-07',
        ]);

        $session = $this->service()->generate($tpl, $admin)->first();

        // Référence : même instant via le chemin SessionForm (Carbon::parse(..., tz club)).
        $tz = ClubSettings::current()->timezone;
        $manual = Carbon::parse('2026-09-07T19:00', $tz);
        $this->assertSame(
            $manual->utc()->format('Y-m-d H:i:s'),
            $session->start_at->copy()->utc()->format('Y-m-d H:i:s'),
            'La séance générée doit stocker le même instant qu’une création manuelle à 19:00.'
        );
        // Affichée en heure locale club : 19:00 le 7 septembre.
        $local = $session->start_at->copy()->setTimezone($tz);
        $this->assertSame('2026-09-07', $local->toDateString());
        $this->assertSame('19:00', $local->format('H:i'));
    }

    public function test_generated_sessions_get_default_coaches_and_activity_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $c1 = User::factory()->coach()->create();
        $c2 = User::factory()->coach()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);
        $tpl->defaultCoaches()->sync([$c1->id, $c2->id]);

        $created = $this->service()->generate($tpl, $admin);

        // Chaque séance a les 2 coachs par défaut pré-affectés (§4.8).
        $this->assertCount(4, $created);
        foreach ($created as $s) {
            $this->assertEqualsCanonicalizing([$c1->id, $c2->id], $s->coaches()->pluck('users.id')->all());
        }

        // N entrées ActivityLog coach_registered (4 séances × 2 coachs = 8), traçabilité fine §4.8.
        $this->assertSame(8, ActivityLog::where('action', 'coach_registered')->count());
        $this->assertSame(4, ActivityLog::where('action', 'coach_registered')->where('user_id', $c1->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'generate_sessions', 'actor_id' => $admin->id]);
    }

    public function test_generated_sessions_get_target_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 1]);
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-07', 'generation_end_date' => '2026-09-07',
        ]);
        $tpl->categories()->sync([$cat->id]);

        $created = $this->service()->generate($tpl, $admin);

        $this->assertCount(1, $created);
        $this->assertSame([$cat->id], $created->first()->categories()->pluck('categories.id')->all());
    }

    public function test_relaunch_adds_sessions_without_erasing_previous(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);

        $first = $this->service()->generate($tpl, $admin); // 4 lundis de septembre
        $this->assertCount(4, $first);

        // Relance sur octobre : 4 nouveaux lundis, les 4 de septembre intacts.
        $relaunched = $this->service()->relaunch($tpl, $admin, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertCount(4, $relaunched);
        $this->assertSame(8, Session::where('source_template_id', $tpl->id)->count());
        // Les séances de septembre ne sont pas écrasées.
        $this->assertTrue($first->first()->fresh()->exists);
    }

    public function test_non_training_template_does_not_set_quota_tag(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'kind' => 'club_event', 'day_of_week' => 1,
            'generation_start_date' => '2026-09-07', 'generation_end_date' => '2026-09-07',
        ]);

        $created = $this->service()->generate($tpl, $admin);

        $this->assertNull($created->first()->quota_tag_id);
    }
}
