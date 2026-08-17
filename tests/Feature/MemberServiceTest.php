<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Qualification;
use App\Models\User;
use App\Services\MemberService;
use App\Support\AgeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Service adhérents J6.2 (PRD §4.17.1, §4.1.3, §4.5) : dérivation catégorie, création + logs,
// mutations rôles/catégories/qualifs au geste.
class MemberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCategories(): array
    {
        return [
            'minime' => Category::create(['label' => 'Minimes', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 1]),
            'senior' => Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 2]),
            'master' => Category::create(['label' => 'Master', 'age_min' => 40, 'age_max' => 99, 'sort_order' => 3]),
        ];
    }

    public function test_season_age_uses_sport_year_end(): void
    {
        // Référence : 31 août de fin de saison. Au 18 juin 2026, la saison court depuis sept 2025
        // → fin = 31 août 2026. Un athlète né le 1er sept 2006 a 19 ans au 31 août 2026.
        $on = Carbon::create(2026, 6, 18);
        $dob = Carbon::create(2006, 9, 1);

        $this->assertSame(19, AgeCategory::seasonAge($dob, $on));
    }

    public function test_derive_picks_matching_active_category(): void
    {
        $cats = $this->seedCategories();
        $on = Carbon::create(2026, 6, 18);

        // 25 ans au 31 août 2026 → né en 2001.
        $derived = AgeCategory::derive(Carbon::create(2001, 1, 1), $on);

        $this->assertNotNull($derived);
        $this->assertSame($cats['senior']->id, $derived->id);
    }

    public function test_derive_returns_null_when_no_category_covers_age(): void
    {
        $this->seedCategories(); // pas de catégorie < 13 ans
        $on = Carbon::create(2026, 6, 18);

        $this->assertNull(AgeCategory::derive(Carbon::create(2020, 1, 1), $on));
    }

    public function test_create_sets_primary_category_minor_flag_and_logs(): void
    {
        $cats = $this->seedCategories();
        $admin = User::factory()->admin()->create();

        $member = app(MemberService::class)->create([
            'first_name' => 'Camille',
            'last_name' => 'Vincent',
            // dob relative à la saison courante (âge de saison = 14 → Minimes, mineur) : une
            // année en dur casse à chaque bascule de saison (sept), l'âge §4.5 glissant d'un an.
            'dob' => ((Carbon::now()->month >= 9 ? Carbon::now()->year + 1 : Carbon::now()->year) - 14).'-01-01',
            'email' => null,
            'roles' => ['athlete'],
            'surclassements' => [$cats['senior']->id],
            'qualifications' => [],
            'guardian_id' => null,
        ], $admin);

        $member->refresh();
        $this->assertTrue($member->is_minor);
        $this->assertSame($cats['minime']->id, $member->primaryCategory()->id);
        $this->assertTrue($member->categories()->where('category_id', $cats['senior']->id)->wherePivot('is_primary', false)->exists());

        $this->assertDatabaseHas('activity_logs', ['action' => 'create_member', 'user_id' => $member->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member_created', 'target_id' => $member->id]);
    }

    public function test_toggle_role_grants_then_revokes_with_both_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['roles' => ['athlete']]);
        $service = app(MemberService::class);

        $granted = $service->toggleRole($member, 'coach', $admin);
        $this->assertTrue($granted);
        $this->assertContains('coach', $member->fresh()->roles);

        $service->toggleRole($member, 'coach', $admin);
        $this->assertNotContains('coach', $member->fresh()->roles);

        $this->assertSame(1, ActivityLog::where('action', 'role_granted')->count());
        $this->assertSame(1, ActivityLog::where('action', 'role_revoked')->count());
        $this->assertSame(2, AuditLog::where('action', 'role_changed')->count());
    }

    public function test_add_and_remove_surclassement(): void
    {
        $cats = $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $service = app(MemberService::class);

        $service->addSurclassement($member, $cats['master'], $admin);
        $this->assertTrue($member->categories()->where('category_id', $cats['master']->id)->exists());

        $service->removeSurclassement($member, $cats['master'], $admin);
        $this->assertFalse($member->fresh()->categories()->where('category_id', $cats['master']->id)->exists());
    }

    public function test_remove_surclassement_never_drops_primary(): void
    {
        $cats = $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $member->categories()->attach($cats['senior']->id, ['is_primary' => true]);
        $service = app(MemberService::class);

        $service->removeSurclassement($member, $cats['senior'], $admin);

        // La principale reste rattachée (no-op).
        $this->assertTrue($member->fresh()->categories()->where('category_id', $cats['senior']->id)->exists());
    }

    public function test_qualification_assign_and_revoke_with_pivot(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $qual = Qualification::create(['label' => 'BF5', 'code' => 'BF5', 'sort_order' => 1]);
        $service = app(MemberService::class);

        $service->addQualification($member, $qual, $admin);
        $pivot = $member->fresh()->qualifications()->first()->pivot;
        $this->assertSame($admin->id, $pivot->attributed_by);
        $this->assertNotNull($pivot->attributed_at);

        $service->removeQualification($member, $qual, $admin);
        $this->assertFalse($member->fresh()->qualifications()->where('qualification_id', $qual->id)->exists());

        $this->assertSame(1, ActivityLog::where('action', 'qualification_assigned')->count());
        $this->assertSame(1, ActivityLog::where('action', 'qualification_revoked')->count());
    }
}
