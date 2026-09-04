<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberCreate;
use App\Models\ClubSettings;
use App\Models\User;
use App\Services\GuardianshipService;
use App\Support\AgeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// La tutelle (§4.2) se règle sur l'âge LÉGAL du jour, la catégorie sportive (§4.5) sur l'âge de
// saison. Confondues, elles déclaraient majeur — jusqu'à douze mois à l'avance — un adhérent
// légalement mineur : plus de garant à la création, plus de rattachement, et l'import refusant sa
// ligne faute d'email. Carnet de retours terrain, entrée du 2026-09-04.
class TutelleAgeLegalTest extends TestCase
{
    use RefreshDatabase;

    /** Né le 25/08/2009 : 17 ans au 04/09/2026, mais 18 ans à la clôture de saison (31/08/2027). */
    private const DOB_ADO = '2009-08-25';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 4, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Le cœur du constat : les deux âges divergent, et c'est le légal qui gouverne la tutelle. */
    public function test_lage_legal_diverge_de_lage_de_saison(): void
    {
        $dob = Carbon::parse(self::DOB_ADO);

        $this->assertSame(18, AgeCategory::seasonAge($dob), 'À la clôture de saison, il aura 18 ans.');
        $this->assertSame(17, AgeCategory::legalAge($dob), "Aujourd'hui, il en a 17.");
        $this->assertTrue(AgeCategory::isLegallyMinor($dob));
    }

    public function test_lage_legal_ne_depend_pas_du_mois_de_bascule(): void
    {
        $dob = Carbon::parse(self::DOB_ADO);

        $septembre = AgeCategory::isLegallyMinor($dob);
        ClubSettings::current()->update(['season_start_month' => 1]);
        ClubSettings::flushCache();

        $this->assertSame($septembre, AgeCategory::isLegallyMinor($dob));
    }

    /** Rattachement d'un garant à un adhérent que seul l'âge de saison déclarait majeur. */
    public function test_link_accepte_un_pupille_legalement_mineur(): void
    {
        $parent = User::factory()->create();
        $ado = User::factory()->create(['dob' => self::DOB_ADO, 'email' => null, 'password' => null]);
        $admin = User::factory()->admin()->create();

        app(GuardianshipService::class)->link($ado, $parent, $admin);

        $this->assertSame($parent->id, $ado->fresh()->guardian_id);
        $this->assertNotNull($ado->fresh()->guardianship_linked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guardianship_linked', 'target_id' => $ado->id]);
    }

    /** Sans date de naissance, la minorité n'est pas établie : on refuse plutôt que de supposer. */
    public function test_link_refuse_un_pupille_sans_date_de_naissance(): void
    {
        $parent = User::factory()->create();
        $sansDob = User::factory()->create(['dob' => null, 'email' => null]);
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->link($sansDob, $parent, $admin);
    }

    /** Contrôle positif : un adulte reste refusé — le périmètre ne s'élargit pas aux majeurs. */
    public function test_link_refuse_toujours_un_majeur(): void
    {
        $parent = User::factory()->create();
        $adulte = User::factory()->create(['dob' => '1990-01-01', 'email' => null]);
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        app(GuardianshipService::class)->link($adulte, $parent, $admin);
    }

    /** Le formulaire de création accepte P1 (sans email) pour un ado légalement mineur. */
    public function test_le_formulaire_cree_un_p1_pour_un_ado_legalement_mineur(): void
    {
        $admin = User::factory()->admin()->create();
        $parent = User::factory()->create();

        Livewire::actingAs($admin)->test(MemberCreate::class)
            ->set('first_name', 'Lila')
            ->set('last_name', 'Berger')
            ->set('dob', self::DOB_ADO)
            ->set('phase', 'P1')
            ->set('guardian_id', $parent->id)
            ->call('create')
            ->assertHasNoErrors();

        $lila = User::where('first_name', 'Lila')->firstOrFail();
        $this->assertNull($lila->email, 'Un P1 n\'a pas de compte propre.');
        $this->assertSame($parent->id, $lila->guardian_id);
    }

    /**
     * Un adulte de 19 ans, créé alors qu'il était mineur, reste éligible comme garant.
     *
     * C'était le défaut exact de la minorité stockée : écrite à la création et jamais recalculée,
     * elle refusait comme garant quelqu'un que la base croyait encore mineur. Rien à rafraîchir
     * désormais — la minorité se lit sur la date de naissance à chaque question posée.
     */
    public function test_un_adulte_cree_mineur_reste_eligible_comme_garant(): void
    {
        $admin = User::factory()->admin()->create();
        $garant = User::factory()->create(['dob' => '2007-03-01']);

        Livewire::actingAs($admin)->test(MemberCreate::class)
            ->set('first_name', 'Noé')
            ->set('last_name', 'Bertin')
            ->set('dob', '2014-05-02')
            ->set('phase', 'P1')
            ->set('guardian_id', $garant->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame($garant->id, User::where('first_name', 'Noé')->firstOrFail()->guardian_id);
    }
}
