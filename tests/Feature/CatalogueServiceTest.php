<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\QuotaTag;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\CatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

// Catalogues paramétrables J6.1 (PRD §4.6, §4.17) : CRUD, archivage soft, suppression dure si
// zéro référence, garde-fou min-1-actif (disciplines, types d'épreuve), AuditLog *_modified.
class CatalogueServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CatalogueService
    {
        return app(CatalogueService::class);
    }

    public function test_create_and_audit(): void
    {
        $admin = User::factory()->admin()->create();

        $d = $this->service()->create('discipline', ['label' => 'Aquathlon'], $admin);

        $this->assertDatabaseHas('disciplines', ['label' => 'Aquathlon']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'discipline_modified', 'target_id' => $d->id, 'motif' => 'create',
        ]);
    }

    public function test_update_is_retroactive_by_id(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);

        $this->service()->update('quota_tag', $tag, ['label' => 'Bassin', 'max_per_week' => 3], $admin);

        $this->assertSame('Bassin', $tag->fresh()->label);
        $this->assertSame(3, $tag->fresh()->max_per_week);
    }

    public function test_archive_soft_keeps_history(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = QuotaTag::create(['code' => 'cap', 'label' => 'CAP', 'max_per_week' => 3]);

        $this->service()->archive('quota_tag', $tag, $admin);

        $this->assertNotNull($tag->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quota_tag_modified', 'motif' => 'archive']);
    }

    public function test_restore_reactivates(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = QuotaTag::create(['code' => 'velo', 'label' => 'Vélo', 'max_per_week' => 2, 'archived_at' => Carbon::now()]);

        $this->service()->restore('quota_tag', $tag, $admin);

        $this->assertNull($tag->fresh()->archived_at);
    }

    public function test_last_active_discipline_cannot_be_archived(): void
    {
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete(); // table vierge
        $only = Discipline::create(['label' => 'Natation']);

        try {
            $this->service()->archive('discipline', $only, $admin);
            $this->fail('Attendu : MUST_KEEP_ONE_ACTIVE');
        } catch (RuntimeException $e) {
            $this->assertSame(CatalogueService::MUST_KEEP_ONE_ACTIVE, $e->getMessage());
        }

        $this->assertNull($only->fresh()->archived_at); // toujours active
    }

    public function test_archiving_one_of_two_disciplines_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete();
        $a = Discipline::create(['label' => 'Natation']);
        Discipline::create(['label' => 'Vélo']);

        $this->service()->archive('discipline', $a, $admin);

        $this->assertNotNull($a->fresh()->archived_at);
    }

    public function test_last_active_event_type_cannot_be_archived(): void
    {
        $admin = User::factory()->admin()->create();
        EventType::query()->delete();
        $only = EventType::create(['label' => 'Triathlon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CatalogueService::MUST_KEEP_ONE_ACTIVE);
        $this->service()->archive('event_type', $only, $admin);
    }

    public function test_delete_blocked_when_referenced(): void
    {
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete();
        $keep = Discipline::create(['label' => 'Natation']); // garde min-1 actif satisfait
        $used = Discipline::create(['label' => 'Vélo']);

        Session::create([
            'kind' => 'training', 'title' => 'Sortie', 'discipline_id' => $used->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 60, 'created_by' => $admin->id,
        ]);

        try {
            $this->service()->delete('discipline', $used, $admin);
            $this->fail('Attendu : STILL_REFERENCED');
        } catch (RuntimeException $e) {
            $this->assertSame(CatalogueService::STILL_REFERENCED, $e->getMessage());
        }

        $this->assertDatabaseHas('disciplines', ['id' => $used->id]);
        $this->assertNotNull($keep->fresh()); // garde inchangée
    }

    public function test_delete_allowed_when_zero_reference(): void
    {
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete();
        Discipline::create(['label' => 'Natation']); // garde min-1 actif
        $orphan = Discipline::create(['label' => 'Obsolète']);

        $this->service()->delete('discipline', $orphan, $admin);

        $this->assertDatabaseMissing('disciplines', ['id' => $orphan->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'discipline_modified', 'motif' => 'delete']);
    }

    public function test_delete_blocked_when_referenced_by_template_only(): void
    {
        // Une discipline n'est utilisée par AUCUNE Session mais par un SessionTemplate : la
        // suppression dure doit quand même être bloquée (sinon FK template orpheline §4.6 / J5).
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete();
        Discipline::create(['label' => 'Natation']); // garde min-1 actif
        $used = Discipline::create(['label' => 'Vélo']);

        SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'discipline_id' => $used->id,
        ]);

        try {
            $this->service()->delete('discipline', $used, $admin);
            $this->fail('Attendu : STILL_REFERENCED (référence template)');
        } catch (RuntimeException $e) {
            $this->assertSame(CatalogueService::STILL_REFERENCED, $e->getMessage());
        }

        $this->assertDatabaseHas('disciplines', ['id' => $used->id]);
    }

    public function test_update_ignores_archive_column(): void
    {
        // update() ne doit pas pouvoir archiver via un champ form (garde min-1-actif contournée).
        $admin = User::factory()->admin()->create();
        Discipline::query()->delete();
        $only = Discipline::create(['label' => 'Natation']);

        $this->service()->update('discipline', $only, ['label' => 'Nat', 'archived_at' => now()], $admin);

        $this->assertSame('Nat', $only->fresh()->label);
        $this->assertNull($only->fresh()->archived_at); // archive col ignorée
    }

    public function test_category_reference_count_covers_users_and_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 1]);
        $cat->users()->attach($admin->id, ['is_primary' => true]);

        // Référencée par un user → suppression dure bloquée (archivage attendu à la place).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CatalogueService::STILL_REFERENCED);
        $this->service()->delete('category', $cat, $admin);
    }
}
