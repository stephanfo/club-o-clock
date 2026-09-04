<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberShow;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\GuardianshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Reprise du lien de tutelle côté admin (§4.2, extension). Le garant se posait à la création et ne
// se changeait plus : celui d'un P1 était définitif, faute de pouvoir rompre sans laisser l'enfant
// sans garant ni accès. Carnet de retours terrain, entrée du 2026-09-04.
class TutelleChangementGarantTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: User} parent garant, pupille P1, admin */
    private function famille(?string $emailPupille = null): array
    {
        $parent = User::factory()->create(['first_name' => 'Claire']);
        $pupille = User::factory()->create([
            'first_name' => 'Léo',
            'dob' => Carbon::now()->subYears(12)->toDateString(),
            'email' => $emailPupille,
            'password' => null,
            'guardian_id' => $parent->id,
            'guardianship_linked_at' => Carbon::now(),
        ]);

        return [$parent, $pupille, User::factory()->admin()->create()];
    }

    public function test_le_garant_est_remplace_en_une_transaction(): void
    {
        [$sortant, $pupille, $admin] = $this->famille();
        $entrant = User::factory()->create(['first_name' => 'Yanis']);

        app(GuardianshipService::class)->relink($pupille, $entrant, $admin);

        $this->assertSame($entrant->id, $pupille->fresh()->guardian_id);
        $this->assertNotNull($pupille->fresh()->guardianship_linked_at);
        $this->assertNotSame($sortant->id, $pupille->fresh()->guardian_id);
    }

    /** Les deux verbes habituels, pas un troisième : chaque moitié reste cherchable. */
    public function test_le_remplacement_trace_la_rupture_et_le_rattachement(): void
    {
        [, $pupille, $admin] = $this->famille();
        $entrant = User::factory()->create();

        app(GuardianshipService::class)->relink($pupille, $entrant, $admin);

        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_severed', 'target_id' => $pupille->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_linked', 'target_id' => $pupille->id]);
    }

    /** Le garant sortant est prévenu ; le pupille, lui, n'a rien perdu. */
    public function test_seul_le_garant_sortant_est_notifie(): void
    {
        [$sortant, $pupille, $admin] = $this->famille('leo@example.test');
        $entrant = User::factory()->create();

        app(GuardianshipService::class)->relink($pupille, $entrant, $admin);

        $this->assertGreaterThan(0, NotificationOutbox::where('type', 'guardianship_severed')
            ->where('user_id', $sortant->id)->count());
        $this->assertSame(0, NotificationOutbox::where('type', 'guardianship_severed')
            ->where('user_id', $pupille->id)->count(), 'Le pupille garde un garant : « rompu » serait faux.');
        $this->assertSame(0, NotificationOutbox::where('user_id', $entrant->id)->count());
    }

    /** Le sujet est posé à la main : un garant de plusieurs enfants doit savoir lequel. */
    public function test_la_notification_du_sortant_nomme_le_pupille(): void
    {
        [$sortant, $pupille, $admin] = $this->famille();
        $entrant = User::factory()->create();

        app(GuardianshipService::class)->relink($pupille, $entrant, $admin);

        $ligne = NotificationOutbox::where('type', 'guardianship_severed')
            ->where('user_id', $sortant->id)->firstOrFail();
        $this->assertSame($pupille->id, $ligne->payload['subject_id']);
        $this->assertSame('Léo', $ligne->payload['subject_first_name']);
    }

    public function test_le_remplacement_refuse_un_pupille_sans_garant(): void
    {
        $orphelin = User::factory()->create(['dob' => Carbon::now()->subYears(10)->toDateString(), 'email' => null]);
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->relink($orphelin, User::factory()->create(), $admin);
    }

    public function test_le_remplacement_refuse_le_garant_deja_en_place(): void
    {
        [$sortant, $pupille, $admin] = $this->famille();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->relink($pupille, $sortant, $admin);
    }

    /** L'échec ne doit jamais laisser le pupille sans garant — c'est tout l'intérêt de la transaction. */
    public function test_un_remplacement_refuse_laisse_le_lien_intact(): void
    {
        [$sortant, $pupille, $admin] = $this->famille();
        $mineur = User::factory()->create(['dob' => Carbon::now()->subYears(9)->toDateString()]);

        try {
            app(GuardianshipService::class)->relink($pupille, $mineur, $admin);
            $this->fail('Un mineur ne peut pas devenir garant.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame($sortant->id, $pupille->fresh()->guardian_id, 'Le lien d\'origine doit être rendu.');
    }

    public function test_le_remplacement_refuse_un_garant_inactif(): void
    {
        [, $pupille, $admin] = $this->famille();
        $inactif = User::factory()->create(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->relink($pupille, $inactif, $admin);
    }

    // ── L'écran ─────────────────────────────────────────────────────────────────────────────

    public function test_la_fiche_change_le_garant_apres_accuse_de_reception(): void
    {
        [, $pupille, $admin] = $this->famille();
        $entrant = User::factory()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pupille])
            ->call('openRelink')
            ->assertSet('relinkCheck', false)
            ->set('relinkGuardianId', $entrant->id)
            ->set('relinkCheck', true)
            ->call('relinkGuardian')
            ->assertHasNoErrors()
            ->assertSet('relinkDialog', false);

        $this->assertSame($entrant->id, $pupille->fresh()->guardian_id);
    }

    /** Le bouton grisé ne suffit pas : l'état vient du client, le refus est gardé côté serveur. */
    public function test_la_fiche_refuse_le_changement_sans_accuse_de_reception(): void
    {
        [$sortant, $pupille, $admin] = $this->famille();
        $entrant = User::factory()->create();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pupille])
            ->call('openRelink')
            ->set('relinkGuardianId', $entrant->id)
            ->set('relinkCheck', false)
            ->call('relinkGuardian');

        $this->assertSame($sortant->id, $pupille->fresh()->guardian_id);
    }

    /** L'accusé n'est jamais pré-coché : chaque ouverture le remet à zéro. */
    public function test_louverture_du_dialog_remet_laccuse_a_zero(): void
    {
        [, $pupille, $admin] = $this->famille();

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pupille])
            ->set('relinkCheck', true)
            ->call('openRelink')
            ->assertSet('relinkCheck', false)
            ->assertSet('relinkGuardianId', null);
    }

    /** Contrôle positif apparié : l'entrée n'apparaît que là où elle a un sens. */
    public function test_lentree_de_changement_est_absente_sans_garant_et_presente_avec(): void
    {
        [, $pupille, $admin] = $this->famille();
        $orphelin = User::factory()->create([
            'dob' => Carbon::now()->subYears(10)->toDateString(), 'email' => null, 'guardian_id' => null,
        ]);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pupille])
            ->assertSee('Changer de garant');
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $orphelin])
            ->assertDontSee('Changer de garant');
    }
}
