<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\GuardianshipService;
use App\Services\MemberService;
use App\Services\SeasonService;
use App\Services\TrombiService;
use App\Support\Trombi\BoardRole;
use App\Support\Trombi\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Cycle de vie du trombinoscope (#72, PRD §4.23.4, cadrage §7.18) : bascule de saison, suppression
// du compte, rupture et changement de garant, révocation d'une version périmée.
class TrombiLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
    }

    private function photo(User $u): void
    {
        $img = imagecreatetruecolor(40, 40);
        $file = tempnam(sys_get_temp_dir(), 'trombi');
        imagejpeg($img, $file);
        app(TrombiService::class)->storePhoto($u, $file, $u);
    }

    /** Une ligne du registre posée telle quelle : l'historique d'un accord, quel qu'en soit l'auteur. */
    private function consent(User $subject, ConsentType $type, ?User $by, ?int $version = null): Consent
    {
        return Consent::create([
            'user_id' => $subject->id, 'type' => $type, 'version' => $version ?? $type->version(),
            'granted_at' => Carbon::now(), 'granted_by' => $by?->id,
        ]);
    }

    public function test_bascule_purge_les_seuls_non_readherents_de_la_saison_ecoulee(): void
    {
        $stale = User::factory()->create(['athlete_access_suspended' => true]);
        app(TrombiService::class)->setPhone($stale, '0612345678', $stale);
        $this->photo($stale);
        app(ConsentService::class)->grant($stale, ConsentType::Photo, $stale);
        $path = $stale->fresh()->photo_path;

        $renewed = User::factory()->create();
        app(ConsentService::class)->grant($renewed, ConsentType::FullName, $renewed);
        $coach = User::factory()->coach()->create(['athlete_access_suspended' => true]);
        $coach->forceFill(['roles' => ['athlete', 'coach']])->save();
        app(ConsentService::class)->grant($coach, ConsentType::FullName, $coach);

        $this->assertSame(1, app(SeasonService::class)->impactCounters()['trombi_purge']);
        $result = app(SeasonService::class)->deactivateAllAthletes($this->admin);

        $this->assertSame(1, $result['trombi_purged']);
        $stale->refresh();
        $this->assertNull($stale->photo_path);
        $this->assertNull($stale->phone);
        $this->assertFalse($stale->hasConsent(ConsentType::Photo));
        Storage::disk('local')->assertMissing(TrombiService::photoFile($stale->forceFill(['photo_path' => $path]), 192));

        // Suspendu PAR cette bascule : il garde ses accords s'il revient (§4.23.5).
        $this->assertTrue($renewed->fresh()->athlete_access_suspended);
        $this->assertTrue($renewed->fresh()->hasConsent(ConsentType::FullName));
        $this->assertTrue($coach->fresh()->hasConsent(ConsentType::FullName));
        $this->assertStringContainsString('1 trombinoscopes effacés', AuditLog::query()->where('action', 'bulk_athlete_deactivation')->sole()->motif);
    }

    public function test_la_suppression_du_compte_efface_tout_le_trombinoscope(): void
    {
        $member = User::factory()->create();
        app(TrombiService::class)->setPhone($member, '0612345678', $member);
        $this->photo($member);
        app(ConsentService::class)->grant($member, ConsentType::Photo, $member);
        $member->forceFill(['board_role' => BoardRole::Secretary])->save();
        $file = TrombiService::photoFile($member->fresh(), 512);
        Storage::disk('local')->assertExists($file);
        $member->forceFill(['deletion_requested_at' => Carbon::now()->subDays(8), 'is_active' => false])->save();

        app(MemberService::class)->confirmDeletion($member->refresh(), $this->admin);

        $member->refresh();
        $this->assertNull($member->photo_path);
        $this->assertNull($member->phone);
        $this->assertNull($member->getRawOriginal('board_role'));
        $this->assertSame(0, Consent::query()->where('user_id', $member->id)->count());
        Storage::disk('local')->assertMissing($file);
    }

    public function test_la_rupture_fait_tomber_les_accords_d_un_tiers(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $ward = User::factory()->create(['guardian_id' => $parent->id, 'dob' => Carbon::now()->subYears(18)->subDay()->toDateString()]);
        $this->consent($ward, ConsentType::Photo, $parent);
        $this->consent($ward, ConsentType::Birthday, $this->admin);
        $this->consent($ward, ConsentType::FullName, $ward);

        app(GuardianshipService::class)->sever($ward, $this->admin);

        $ward->refresh();
        $this->assertFalse($ward->hasConsent(ConsentType::Photo));
        $this->assertFalse($ward->hasConsent(ConsentType::Birthday));
        $this->assertTrue($ward->hasConsent(ConsentType::FullName), 'Ce que le jeune a accordé lui-même reste.');
    }

    public function test_le_changement_de_garant_fait_tomber_les_accords_du_precedent(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $next = User::factory()->create(['roles' => []]);
        $ward = User::factory()->minorP1()->create(['guardian_id' => $parent->id]);
        app(ConsentService::class)->grant($ward, ConsentType::Photo, $parent);
        $this->assertTrue($ward->fresh()->hasConsent(ConsentType::Photo));

        app(GuardianshipService::class)->relink($ward, $next, $this->admin);

        $this->assertFalse($ward->fresh()->hasConsent(ConsentType::Photo));
        $this->assertSame(1, Consent::query()->where('user_id', $ward->id)->whereNotNull('revoked_at')->count());
    }

    public function test_commande_revoque_les_versions_perimees_seules(): void
    {
        $u = User::factory()->create();
        $old = $this->consent($u, ConsentType::Photo, $u, version: ConsentType::Photo->version() - 1);
        $current = $this->consent($u, ConsentType::FullName, $u);
        $otherType = $this->consent($u, ConsentType::Email, $u, version: ConsentType::Email->version() - 1);

        $this->artisan('club:trombi-revoquer', ['type' => ConsentType::Photo->value])->assertSuccessful();
        $this->artisan('club:trombi-revoquer', ['type' => 'photo'])->assertFailed();

        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull($current->fresh()->revoked_at);
        $this->assertNull($otherType->fresh()->revoked_at, 'Seul le type demandé est révoqué.');
        $this->assertSame(1, AuditLog::query()->where('action', 'consents_outdated_revoked')->count());
    }
}
