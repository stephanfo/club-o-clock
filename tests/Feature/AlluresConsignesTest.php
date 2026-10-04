<?php

namespace Tests\Feature;

use App\Models\AllureZone;
use App\Models\Discipline;
use App\Models\ReferenceValue;
use App\Models\Session;
use App\Models\User;
use App\Services\AlluresService;
use App\Support\Allures\Annotateur;
use App\Support\Allures\Referentiel;
use App\Support\Markup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Zones reconnues dans les consignes de séance (#113) : allure du LECTEUR ajoutée à côté du
// code, sans toucher au Markdown stocké ; seulement si la discipline a un référentiel.
// Grille de test inventée (aucun coefficient de coach).
class AlluresConsignesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AllureZone::query()->delete();
        foreach ([['Z3', 85, 90], ['Z3−', 80, 84], ['Z4', 90, 95], ['Z4+', 95, 98]] as [$code, $min, $max]) {
            AllureZone::create(['referentiel' => 'course', 'code' => $code, 'label' => $code, 'pct_min' => $min, 'pct_max' => $max]);
        }
    }

    private function annoter(string $markdown, ?float $vma = 15.0): array
    {
        return Annotateur::annoter((string) Markup::render($markdown), AllureZone::activeFor(Referentiel::Course), $vma);
    }

    private function seance(?string $referentiel, string $consigne = "4x (2' Z4 / 2' Z3)"): Session
    {
        $discipline = Discipline::create(['label' => 'Course test '.uniqid(), 'referentiel' => $referentiel, 'sort_order' => 9]);

        return Session::create([
            'kind' => 'training', 'title' => 'Fractionné', 'discipline_id' => $discipline->id,
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'content_markdown' => $consigne,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    private function avecVma(float $vma): User
    {
        $user = User::factory()->create();
        app(AlluresService::class)->setReference($user, Referentiel::Course, $vma, ReferenceValue::SOURCE_SAISIE);

        return $user;
    }

    // ── Motif ──

    public function test_le_code_le_plus_long_l_emporte(): void
    {
        // 15 km/h : Z4+ (95–98 %) = 4:05–4:13 ; Z4 (90–95 %) = 4:13–4:27.
        [$html] = $this->annoter('Z4+ puis Z4');
        $this->assertStringContainsString('Z4+<span class="zone-allure"> (4:05–4:13 /km)</span>', $html);
        $this->assertStringContainsString('Z4<span class="zone-allure"> (4:13–4:27 /km)</span>', $html);
    }

    public function test_signe_moins_et_plage(): void
    {
        // « Z3- » (tiret ASCII) = la zone Z3− ; « Z3-Z4 » = une plage, une seule fourchette de
        // l'allure la plus rapide de Z4 à la plus lente de Z3, quel que soit l'ordre d'écriture.
        [$html] = $this->annoter('Z3- puis Z3-Z4 puis Z4−Z3');
        $this->assertStringContainsString('Z3-<span class="zone-allure"> (4:46–5:00 /km)</span>', $html);
        $this->assertStringContainsString('Z3-Z4<span class="zone-allure"> (4:13–4:42 /km)</span>', $html);
        $this->assertStringContainsString('Z4−Z3<span class="zone-allure"> (4:13–4:42 /km)</span>', $html);
        $this->assertSame(3, substr_count($html, 'zone-allure'));
    }

    public function test_ni_dans_un_lien_ni_colle_a_un_mot(): void
    {
        [$html, $reconnu] = $this->annoter('[Z4](https://exemple.fr/Z4) et Z4x et AZ4');
        $this->assertFalse($reconnu);
        $this->assertStringNotContainsString('zone-allure', $html);
        $this->assertStringContainsString('href="https://exemple.fr/Z4"', $html);

        // Contrôle positif : le même code isolé est reconnu.
        $this->assertTrue($this->annoter('[lien](https://exemple.fr) et Z4')[1]);
    }

    public function test_code_hors_catalogue_ou_archive_non_reconnu(): void
    {
        AllureZone::where('code', 'Z4')->update(['archived_at' => now()]);
        [$html, $reconnu] = $this->annoter('Z4 et Z9');
        $this->assertFalse($reconnu);
        $this->assertStringNotContainsString('zone-allure', $html);
    }

    public function test_sans_vma_texte_inchange_mais_zone_signalee(): void
    {
        $brut = (string) Markup::render('2x Z4');
        [$html, $reconnu] = $this->annoter('2x Z4', null);
        $this->assertSame($brut, $html);
        $this->assertTrue($reconnu);
    }

    // ── Fiche séance ──

    public function test_la_fiche_affiche_l_allure_du_lecteur(): void
    {
        $seance = $this->seance('course');
        $lent = $this->avecVma(12.0);
        $rapide = $this->avecVma(15.0);

        // 12 km/h à 90–95 % = 5:16–5:33 ; 15 km/h = 4:13–4:27. Chacun voit la sienne.
        $this->actingAs($lent)->get(route('sessions.show', $seance))->assertOk()
            ->assertSee('(5:16–5:33 /km)')->assertDontSee('(4:13–4:27 /km)');
        $this->actingAs($rapide)->get(route('sessions.show', $seance))->assertOk()
            ->assertSee('(4:13–4:27 /km)')->assertDontSee('(5:16–5:33 /km)');

        // Le Markdown stocké n'est pas touché.
        $this->assertSame("4x (2' Z4 / 2' Z3)", $seance->refresh()->content_markdown);
    }

    public function test_sans_vma_invitation_a_la_renseigner(): void
    {
        $seance = $this->seance('course');

        $this->actingAs(User::factory()->create())->get(route('sessions.show', $seance))->assertOk()
            ->assertSee('Renseigne ta VMA pour voir tes allures')
            ->assertDontSee('zone-allure', false);
    }

    public function test_pas_d_invitation_sans_zone_reconnue(): void
    {
        $seance = $this->seance('course', 'Footing libre');

        $this->actingAs(User::factory()->create())->get(route('sessions.show', $seance))->assertOk()
            ->assertSee('Footing libre')
            ->assertDontSee('Renseigne ta VMA');
    }

    public function test_discipline_sans_referentiel_rien_n_est_annote(): void
    {
        $seance = $this->seance(null, '3x Z4 au home-trainer');
        $user = $this->avecVma(15.0);

        $this->actingAs($user)->get(route('sessions.show', $seance))->assertOk()
            ->assertSee('3x Z4 au home-trainer')
            ->assertDontSee('zone-allure', false)
            ->assertDontSee('Renseigne ta VMA');
    }
}
