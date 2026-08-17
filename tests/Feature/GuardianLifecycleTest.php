<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Models\NotificationOutbox;
use App\Models\Qualification;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Services\GuardianshipService;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Cycle de vie garant (PRD §4.2 × §4.3) : suppression/désactivation d'un parent, rattachement
// d'un garant par l'admin, visibilité des pupilles, expiration des qualifications (§4.11.3).
class GuardianLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function guardianWith(string $phase): array
    {
        $guardian = User::factory()->create();
        $ward = User::factory()->create([
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            ...($phase === 'P1' ? ['email' => null, 'password' => null] : []),
        ]);

        return [$guardian, $ward];
    }

    // ── Garde RGPD : suppression d'un garant ──

    public function test_deletion_request_blocked_for_guardian_of_p1_ward(): void
    {
        [$guardian] = $this->guardianWith('P1');
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('garant d\'un mineur sans compte propre');
        app(MemberService::class)->requestDeletion($guardian, $admin);
    }

    public function test_deletion_allowed_with_p2_ward_and_severs_on_anonymize(): void
    {
        [$guardian, $ward] = $this->guardianWith('P2');
        $admin = User::factory()->admin()->create();
        $service = app(MemberService::class);

        $service->requestDeletion($guardian, $admin);
        $guardian->forceFill(['deletion_requested_at' => Carbon::now()->subDays(8)])->save();
        $service->confirmDeletion($guardian->fresh(), $admin);

        // La tutelle est rompue proprement AVANT le scrub : pas de pupille pointant un tombstone.
        $this->assertNull($ward->fresh()->guardian_id);
        $this->assertNotNull($guardian->fresh()->anonymized_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_severed']);
    }

    // ── Routage notifications : destinataires injoignables (§4.15.5) ──

    public function test_notifications_skip_anonymized_guardian(): void
    {
        [$guardian, $ward] = $this->guardianWith('P2');
        $guardian->forceFill(['anonymized_at' => Carbon::now(), 'is_active' => false, 'email' => null])->save();

        $lines = app(NotificationDispatcher::class)
            ->dispatch(NotificationType::SessionCancelled, $ward->fresh(), ['session_id' => 1]);

        $this->assertNotEmpty($lines);
        $this->assertSame(0, NotificationOutbox::where('user_id', $guardian->id)->count());
    }

    public function test_p1_child_with_deactivated_guardian_yields_no_recipient(): void
    {
        [$guardian, $ward] = $this->guardianWith('P1');
        $guardian->update(['is_active' => false]);

        $lines = app(NotificationDispatcher::class)
            ->dispatch(NotificationType::SessionCancelled, $ward->fresh(), ['session_id' => 1]);

        // Zéro destinataire joignable : rien en outbox (tracé en log applicatif).
        $this->assertCount(0, $lines);
        $this->assertSame(0, NotificationOutbox::count());
    }

    // ── Rattachement d'un garant par l'admin ──

    public function test_admin_links_guardian_to_autonomous_minor(): void
    {
        $minor = User::factory()->create(['is_minor' => true]);
        $adult = User::factory()->create();
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->link($minor, $adult, $admin);

        $this->assertSame($adult->id, $minor->fresh()->guardian_id);
        $this->assertNotNull($minor->fresh()->guardianship_linked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_linked']);
    }

    public function test_link_refuses_minor_with_existing_guardian_and_minor_guardian(): void
    {
        [, $ward] = $this->guardianWith('P2');
        $adult = User::factory()->create();
        $admin = User::factory()->admin()->create();

        try {
            app(GuardianshipService::class)->link($ward, $adult, $admin);
            $this->fail('Attendu : refus (garant existant)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('déjà un garant', $e->getMessage());
        }

        $orphan = User::factory()->create(['is_minor' => true]);
        $minorGuardian = User::factory()->create(['is_minor' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('adulte actif');
        app(GuardianshipService::class)->link($orphan, $minorGuardian, $admin);
    }

    public function test_member_show_offers_link_for_orphan_minor(): void
    {
        $minor = User::factory()->create(['is_minor' => true]);
        $adult = User::factory()->create();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $minor])
            ->assertSee('sans parent garant')
            ->set('linkGuardianId', $adult->id)
            ->call('linkGuardian');

        $this->assertSame($adult->id, $minor->fresh()->guardian_id);
    }

    // ── Pupilles visibles sur la fiche du garant ──

    public function test_member_show_lists_wards_on_guardian_page(): void
    {
        [$guardian, $ward] = $this->guardianWith('P1');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $guardian])
            ->assertSee('Pupilles')
            ->assertSee($ward->fullName())
            ->assertSee('P1');
    }

    // ── Reparentage depuis la fiche du PARENT : « ajouter un pupille » (miroir de linkGuardian) ──

    public function test_member_show_links_ward_from_guardian_page(): void
    {
        $adult = User::factory()->create(); // adulte actif → peut être garant
        $minor = User::factory()->create(['is_minor' => true]); // mineur sans garant
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $adult])
            ->assertSee('Ajouter un pupille')
            ->set('addingWard', true)
            ->set('linkWardId', $minor->id)
            ->call('linkWard')
            ->assertHasNoErrors()
            ->assertSet('addingWard', false);

        $this->assertSame($adult->id, $minor->fresh()->guardian_id);
    }

    public function test_member_show_re_links_ward_after_sever_from_guardian_page(): void
    {
        // Le lien existait, a été rompu (P2→P3) : l'admin ré-associe l'enfant depuis la fiche du parent.
        [$guardian, $ward] = $this->guardianWith('P2');
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->sever($ward, $admin);
        $this->assertNull($ward->fresh()->guardian_id);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $guardian])
            ->set('addingWard', true)
            ->set('linkWardId', $ward->id)
            ->call('linkWard')
            ->assertHasNoErrors();

        $this->assertSame($guardian->id, $ward->fresh()->guardian_id);
    }

    // ── Qualifications : expiration à l'ajout + édition (§4.11.3) ──

    public function test_add_qualification_with_expiry_and_edit_it(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $qual = Qualification::create(['label' => 'BNSSA', 'code' => 'BNSSA']);

        // Nouveau flux à deux temps : choisir la qualification, PUIS fixer la date, puis attribuer.
        $expiry = Carbon::now()->addYear()->toDateString();
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $coach])
            ->set('addingQual', true)
            ->call('selectQualification', $qual->id)
            ->assertSet('pendingQualId', $qual->id)
            ->set('newQualExpiry', $expiry)
            ->call('addQualification')
            ->assertSet('pendingQualId', null)
            ->assertSet('addingQual', false);

        $this->assertSame($expiry, Carbon::parse($coach->fresh()->qualifications->first()->pivot->expires_at)->toDateString());

        // Édition : passe la qualification en expirée (badge « expirée » sur les fiches séance).
        $past = Carbon::now()->subMonth()->toDateString();
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $coach->fresh()])
            ->call('startEditQualExpiry', $qual->id)
            ->set('editQualExpiry', $past)
            ->call('saveQualExpiry');

        $this->assertSame($past, Carbon::parse($coach->fresh()->qualifications->first()->pivot->expires_at)->toDateString());

        // Effacement : vide = sans expiration.
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $coach->fresh()])
            ->call('startEditQualExpiry', $qual->id)
            ->set('editQualExpiry', '')
            ->call('saveQualExpiry');

        $this->assertNull($coach->fresh()->qualifications->first()->pivot->expires_at);
    }
}
