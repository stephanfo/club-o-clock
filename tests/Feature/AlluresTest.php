<?php

namespace Tests\Feature;

use App\Livewire\Admin\AllureLevels;
use App\Livewire\Admin\CatalogueManager;
use App\Livewire\Allures;
use App\Models\AllureLevel;
use App\Models\AllureZone;
use App\Models\AuditLog;
use App\Models\Discipline;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\AlluresService;
use App\Services\MemberService;
use App\Support\Allures\Referentiel;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

// Allures course (#114) : VMA du profil (valeur courante, visible du seul membre, jamais de
// chrono), estimation Riegel ou table club, catalogue des zones, table club, référentiel des
// disciplines. Les valeurs de table sont inventées : aucun coefficient de coach dans le dépôt.
class AlluresTest extends TestCase
{
    use RefreshDatabase;

    private function vma(User $user): ?ReferenceValue
    {
        return ReferenceValue::where('user_id', $user->id)->where('referentiel', 'course')->first();
    }

    private function zone(string $code, int $min, int $max): AllureZone
    {
        return AllureZone::create(['referentiel' => 'course', 'code' => $code, 'label' => $code, 'pct_min' => $min, 'pct_max' => $max]);
    }

    private function level(string $label = 'Confirmé', int $order = 0): AllureLevel
    {
        return AllureLevel::create(['referentiel' => 'course', 'label' => $label, 'sort_order' => $order, 'targets' => [
            '5k' => [90, 94], '10k' => [86, 90], 'semi' => [80, 84], 'marathon' => [75, null],
        ]]);
    }

    /** Ligne Riegel, posée en production par la migration ou le seed (le dump n'a pas de données). */
    private function riegel(): AllureLevel
    {
        return AllureLevel::create(['referentiel' => 'course', 'label' => AllureLevel::LABEL_RIEGEL, 'sort_order' => 0, 'model' => AllureLevel::MODEL_RIEGEL]);
    }

    // ── VMA du profil ──

    public function test_le_membre_saisit_sa_vma(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Allures::class)
            ->set('vma', '13,6')
            ->call('saveVma')
            ->assertHasNoErrors();

        $row = $this->vma($user);
        $this->assertNotNull($row);
        $this->assertSame(13.6, $row->value);
        $this->assertSame(ReferenceValue::SOURCE_SAISIE, $row->source);
        $this->assertNull($row->source_distance);
        $this->assertTrue($row->measured_on->isToday());
    }

    public function test_une_vma_improbable_est_refusee_cote_serveur(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Allures::class)->set('vma', '30')->call('saveVma');
        Livewire::actingAs($user)->test(Allures::class)->set('vma', '7,9')->call('saveVma');
        $this->assertNull($this->vma($user));

        $this->expectException(InvalidArgumentException::class);
        app(AlluresService::class)->setReference($user, Referentiel::Course, 25.1, ReferenceValue::SOURCE_SAISIE);
    }

    public function test_la_vma_est_ecrasee_sans_historique(): void
    {
        $user = User::factory()->create();
        $service = app(AlluresService::class);
        $service->setReference($user, Referentiel::Course, 12.0, ReferenceValue::SOURCE_SAISIE);
        $service->setReference($user, Referentiel::Course, 13.0, ReferenceValue::SOURCE_SAISIE);

        $this->assertSame(1, ReferenceValue::where('user_id', $user->id)->count());
        $this->assertSame(13.0, $this->vma($user)?->value);
    }

    public function test_estimation_riegel_enregistre_la_distance_jamais_le_temps(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Allures::class)
            ->set('estDistance', '10k')
            ->set('estTime', '40:00')
            ->assertSee('Modèle de Riegel')
            ->call('useEstimate');

        $row = $this->vma($user);
        $this->assertNotNull($row);
        $this->assertSame(ReferenceValue::SOURCE_ESTIMATION, $row->source);
        $this->assertSame('10k', $row->source_distance);
        // 10 km en 40:00 = 15 km/h tenus à ≈ 89,9 % → VMA ≈ 16,7.
        $this->assertEqualsWithDelta(16.7, $row->value, 0.1);
        // Aucune colonne ne porte le chrono : seule la VMA, sa date et sa distance d'origine.
        $this->assertSame(
            ['created_at', 'id', 'measured_on', 'referentiel', 'source', 'source_distance', 'updated_at', 'user_id', 'value'],
            collect(array_keys($row->getAttributes()))->sort()->values()->all(),
        );
        $this->assertStringNotContainsString('40:00', json_encode($row->getAttributes()) ?: '');
    }

    public function test_estimation_improbable_signalee_et_refusee(): void
    {
        $user = User::factory()->create();

        // Marathon en 1h49 : 23 km/h de moyenne, VMA bien au-delà de 25.
        Livewire::actingAs($user)->test(Allures::class)
            ->set('estDistance', 'marathon')
            ->set('estTime', '1h49')
            ->assertSee('Temps improbable')
            ->call('useEstimate');

        $this->assertNull($this->vma($user));
    }

    public function test_table_club_remplace_riegel_et_donne_une_fourchette(): void
    {
        $user = User::factory()->create();
        $level = $this->level();

        Livewire::actingAs($user)->test(Allures::class)
            ->set('levelId', $level->id)
            ->set('estDistance', '10k')
            ->set('estTime', '40:00')
            ->assertSee('Table du club — Confirmé')
            ->assertDontSee('Modèle de Riegel')
            // 15 km/h tenus entre 86 et 90 % → 16,7 à 17,4, milieu enregistré.
            ->assertSee('16,7 – 17,4')
            ->call('useEstimate');

        $this->assertEqualsWithDelta((15 / 0.9 + 15 / 0.86) / 2, $this->vma($user)?->value, 0.06);
    }

    public function test_effacer_sa_vma(): void
    {
        $user = User::factory()->create();
        app(AlluresService::class)->setReference($user, Referentiel::Course, 14.0, ReferenceValue::SOURCE_SAISIE);

        Livewire::actingAs($user)->test(Allures::class)->call('clearVma');

        $this->assertNull($this->vma($user));
    }

    public function test_allures_par_zone_et_projection_affichees(): void
    {
        $user = User::factory()->create();
        $this->zone('Z9', 80, 90);
        app(AlluresService::class)->setReference($user, Referentiel::Course, 15.0, ReferenceValue::SOURCE_SAISIE);

        // 15 km/h : 90 % = 4:27 /km, 80 % = 5:00 /km.
        Livewire::actingAs($user)->test(Allures::class)
            ->assertSee('Z9')
            ->assertSeeInOrder(['<span>4:27</span>', '<span>5:00</span>'], false)
            // Grille de la piste au marathon : 400 m à 90–80 % = 1:47 puis 2:00, sur deux lignes.
            ->assertSeeInOrder(['<span>1:47</span>', '<span>2:00</span>'], false)
            ->assertSee('Marathon')
            // Distances officielles mises en avant, pas les autres.
            ->assertSee('<th class="r col-officielle">5 km</th>', false)
            ->assertSee('<th class="r">3 km</th>', false)
            ->assertSee('Projection de temps de course')
            // VMA enregistrée : les allures en tête, la saisie ensuite.
            ->assertSeeInOrder(['Mon allure selon', 'Mes allures par zone', 'Ma VMA', 'Estimer ma VMA']);
    }

    // ── Confidentialité ──

    public function test_la_vma_n_est_visible_que_de_son_titulaire(): void
    {
        $marie = User::factory()->create();
        app(AlluresService::class)->setReference($marie, Referentiel::Course, 13.7, ReferenceValue::SOURCE_SAISIE);

        // Contrôle positif : Marie voit sa VMA dans l'onglet Allures de son profil.
        $onglet = route('profil', ['tab' => 'allures']);
        $this->actingAs($marie)->get($onglet)->assertOk()->assertSee('13,7');

        // Un autre membre, un coach, un admin : l'onglet ne montre que LEUR propre valeur (vide).
        foreach ([User::factory()->create(), User::factory()->coach()->create(), User::factory()->admin()->create()] as $other) {
            $this->actingAs($other)->get($onglet)->assertOk()->assertSee('Ma VMA')->assertDontSee('13,7');
        }
        // Ni la fiche adhérent côté admin.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.members.show', $marie))->assertOk()->assertDontSee('13,7');
    }

    public function test_ecran_reserve_aux_membres_connectes(): void
    {
        $this->get(route('allures'))->assertRedirect(route('login'));
    }

    public function test_l_ancienne_adresse_mene_a_l_onglet_du_profil(): void
    {
        $this->actingAs(User::factory()->create())->get(route('allures'))
            ->assertRedirect(route('profil', ['tab' => 'allures']));
    }

    public function test_couleurs_d_intensite_reparties_par_rang(): void
    {
        $zones = collect([$this->zone('A', 60, 70), $this->zone('B', 70, 80), $this->zone('C', 80, 90)]);

        $this->assertSame(
            [$zones[0]->id => 'var(--intensite-1)', $zones[1]->id => 'var(--intensite-5)', $zones[2]->id => 'var(--intensite-8)'],
            AllureZone::intensites(AllureZone::activeFor(Referentiel::Course)),
        );
    }

    public function test_la_vma_part_avec_le_compte_anonymise(): void
    {
        $member = User::factory()->create();
        app(AlluresService::class)->setReference($member, Referentiel::Course, 13.0, ReferenceValue::SOURCE_SAISIE);
        $member->forceFill(['deletion_requested_at' => Carbon::now()->subDays(8), 'is_active' => false])->save();

        app(MemberService::class)->confirmDeletion($member->refresh(), User::factory()->admin()->create());

        $this->assertNull($this->vma($member));
    }

    // ── Admin : zones, table club, référentiel ──

    public function test_zones_chevauchantes_refusees_bornes_contigues_acceptees(): void
    {
        $admin = User::factory()->admin()->create();
        AllureZone::query()->delete();
        $this->zone('ZA', 60, 75);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
            ->call('startAdd')
            ->set('form', ['code' => 'ZB', 'label' => 'B', 'pct_min' => 70, 'pct_max' => 80])
            ->call('saveRow')
            ->assertHasErrors('form.pct_max');
        $this->assertFalse(AllureZone::where('code', 'ZB')->exists());

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
            ->call('startAdd')
            ->set('form', ['code' => 'ZB', 'label' => 'B', 'pct_min' => 75, 'pct_max' => 80])
            ->call('saveRow')
            ->assertHasNoErrors();
        $this->assertSame('course', AllureZone::where('code', 'ZB')->value('referentiel'));
        $this->assertTrue(AuditLog::where('action', 'allure_zone_modified')->exists());
    }

    public function test_code_de_zone_unique_et_sans_espace(): void
    {
        $admin = User::factory()->admin()->create();
        AllureZone::query()->delete();
        $this->zone('ZA', 60, 75);

        foreach (['ZA', 'Z A'] as $code) {
            Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
                ->call('startAdd')
                ->set('form', ['code' => $code, 'label' => 'X', 'pct_min' => 90, 'pct_max' => 95])
                ->call('saveRow')
                ->assertHasErrors('form.code');
        }
    }

    public function test_alias_de_zone_normalises_et_affiches(): void
    {
        $admin = User::factory()->admin()->create();
        AllureZone::query()->delete();

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
            ->call('startAdd')
            ->set('form', ['code' => 'Z3', 'label' => 'Seuil', 'aliases' => ' SV2 ,SubT  SV2', 'pct_min' => 85, 'pct_max' => 90])
            ->call('saveRow')
            ->assertHasNoErrors();
        $this->assertSame('SV2, SubT', AllureZone::where('code', 'Z3')->value('aliases'));
        // Badge du curseur : libellé en second, alias entre parenthèses.
        $this->assertSame('Z3 · Seuil (SV2, SubT)', AllureZone::where('code', 'Z3')->firstOrFail()->badge());

        $user = User::factory()->create();
        app(AlluresService::class)->setReference($user, Referentiel::Course, 15.0, ReferenceValue::SOURCE_SAISIE);
        Livewire::actingAs($user)->test(Allures::class)->assertSee('Z3 · SV2 · SubT');
    }

    public function test_alias_ambigu_ou_trop_long_refuse(): void
    {
        $admin = User::factory()->admin()->create();
        AllureZone::query()->delete();
        $this->zone('ZA', 60, 75)->update(['aliases' => 'EF']);

        // Code d'une autre zone, alias d'une autre zone (casse ignorée), son propre code, trop long.
        foreach (['za', 'ef', 'ZB', 'ABCDEFGHIJKLM'] as $alias) {
            Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
                ->call('startAdd')
                ->set('form', ['code' => 'ZB', 'label' => 'B', 'aliases' => $alias, 'pct_min' => 80, 'pct_max' => 85])
                ->call('saveRow')
                ->assertHasErrors('form.aliases');
        }
        // Un code qui reprend l'alias d'une autre zone est refusé aussi.
        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
            ->call('startAdd')
            ->set('form', ['code' => 'EF', 'label' => 'B', 'pct_min' => 80, 'pct_max' => 85])
            ->call('saveRow')
            ->assertHasErrors('form.code');
        $this->assertSame(1, AllureZone::count());

        // Contrôle positif : modifier la zone en gardant ses propres alias passe.
        $za = AllureZone::where('code', 'ZA')->firstOrFail();
        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])
            ->call('startEdit', $za->id)
            ->set('form.aliases', 'EF, Endu')
            ->call('saveRow')
            ->assertHasNoErrors();
        $this->assertSame('EF, Endu', $za->fresh()->aliases);
    }

    public function test_restaurer_une_zone_qui_chevaucherait_est_refuse(): void
    {
        $admin = User::factory()->admin()->create();
        AllureZone::query()->delete();
        $old = $this->zone('ZA', 60, 75);
        $old->update(['archived_at' => now()]);
        $this->zone('ZB', 70, 80);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'allure_zone'])->call('restore', $old->id);

        $this->assertNotNull($old->refresh()->archived_at);
    }

    public function test_admin_enregistre_la_table_club(): void
    {
        $admin = User::factory()->admin()->create();
        $targets = fn ($a, $b) => ['5k' => ['min' => $a, 'max' => $b], '10k' => ['min' => $a, 'max' => $b], 'semi' => ['min' => $a, 'max' => $b], 'marathon' => ['min' => $a, 'max' => '']];
        $riegel = $this->riegel();

        // Lignes envoyées sans Riegel (état client) : la ligne en base n'est ni supprimée ni recréée.
        Livewire::actingAs($admin)->test(AllureLevels::class)
            ->set('rows', [['label' => 'Loisir', 'active' => true, 'targets' => $targets('80', '85')], ['label' => 'Compétition', 'active' => true, 'targets' => $targets('88', '92')]])
            ->call('save')
            ->assertHasNoErrors();

        $levels = AllureLevel::forReferentiel(Referentiel::Course);
        $this->assertSame([AllureLevel::LABEL_RIEGEL, 'Loisir', 'Compétition'], $levels->pluck('label')->all());
        $this->assertSame($riegel->id, $levels[0]->id);
        $this->assertNull($levels[0]->targets);
        $this->assertSame([80.0, 85.0], $levels[1]->target('5k'));
        $this->assertSame([80.0, null], $levels[1]->target('marathon'));
        $this->assertTrue(AuditLog::where('action', 'allure_levels_modified')->exists());
    }

    public function test_riegel_niveau_calcule_non_supprimable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->riegel();

        $c = Livewire::actingAs($admin)->test(AllureLevels::class)
            ->assertSee(AllureLevel::LABEL_RIEGEL)
            // % tenus sur 10 km pour une VMA de 10 à 18 km/h.
            ->assertSee('87 – 90 %')
            ->call('removeRow', 0);
        $this->assertSame(AllureLevel::MODEL_RIEGEL, $c->get('rows')[0]['model']);

        // Ajout d'un niveau sous Riegel, puis déplacement en tête : Riegel garde sa ligne.
        $t = ['min' => '85', 'max' => '90'];
        $c->call('addRow')
            ->set('rows.1.label', 'Club')
            ->set('rows.1.targets', ['5k' => $t, '10k' => $t, 'semi' => $t, 'marathon' => $t])
            ->call('moveUp', 1)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['Club', AllureLevel::LABEL_RIEGEL], AllureLevel::forReferentiel(Referentiel::Course)->pluck('label')->all());
    }

    public function test_un_niveau_desactive_n_est_plus_propose_aux_membres(): void
    {
        $riegel = AllureLevel::create(['referentiel' => 'course', 'label' => AllureLevel::LABEL_RIEGEL, 'model' => AllureLevel::MODEL_RIEGEL, 'sort_order' => 0, 'active' => false]);
        $club = $this->level('Confirmé', 1);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Allures::class)
            ->assertSet('levelId', $club->id)
            ->set('estTime', '40:00')
            ->assertSee('Table du club — Confirmé')
            ->assertDontSee('Modèle de Riegel')
            // Choix forcé sur le niveau désactivé : repli sur le premier actif.
            ->set('levelId', $riegel->id)
            ->assertSee('Table du club — Confirmé');
    }

    public function test_riegel_et_table_club_au_choix_du_membre(): void
    {
        $riegel = AllureLevel::create(['referentiel' => 'course', 'label' => AllureLevel::LABEL_RIEGEL, 'model' => AllureLevel::MODEL_RIEGEL, 'sort_order' => 0]);
        $this->level('Confirmé', 1);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Allures::class)
            ->assertSet('levelId', $riegel->id)
            ->assertSee('aria-label="Niveau"', false)
            ->set('estTime', '40:00')
            ->assertSee('Modèle de Riegel (exposant')
            // Riegel seul n'a pas de fourchette : 10 km en 40:00 → 16,7.
            ->assertSee('16,7');
    }

    public function test_riegel_seul_ne_propose_pas_de_choix_de_niveau(): void
    {
        AllureLevel::create(['referentiel' => 'course', 'label' => AllureLevel::LABEL_RIEGEL, 'model' => AllureLevel::MODEL_RIEGEL, 'sort_order' => 0]);
        $this->level('Réserve', 1)->update(['active' => false]);

        Livewire::actingAs(User::factory()->create())->test(Allures::class)
            ->assertDontSee('aria-label="Niveau"', false)
            ->assertSee('Modèle de Riegel (exposant');
    }

    public function test_au_moins_un_niveau_reste_propose(): void
    {
        $admin = User::factory()->admin()->create();
        $riegel = $this->riegel();

        Livewire::actingAs($admin)->test(AllureLevels::class)
            ->call('toggleActive', 0)
            ->call('save');
        $this->assertTrue($riegel->fresh()->active);

        // Client qui retire la ligne Riegel de son envoi : elle reste en base, telle quelle.
        Livewire::actingAs($admin)->test(AllureLevels::class)
            ->set('rows', [])
            ->call('save');
        $this->assertSame([$riegel->id], AllureLevel::pluck('id')->all());
        $this->assertTrue($riegel->fresh()->active);
    }

    public function test_table_club_accepte_la_virgule_decimale(): void
    {
        $admin = User::factory()->admin()->create();
        $t = ['min' => '85,5', 'max' => '90'];
        $this->riegel();

        Livewire::actingAs($admin)->test(AllureLevels::class)
            ->call('addRow')
            ->set('rows.1.label', 'Club')
            ->set('rows.1.targets', ['5k' => $t, '10k' => $t, 'semi' => $t, 'marathon' => $t])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([85.5, 90.0], AllureLevel::where('label', 'Club')->first()?->target('5k'));
    }

    public function test_table_club_refuse_un_max_inferieur_au_min(): void
    {
        $admin = User::factory()->admin()->create();
        $t = ['min' => '90', 'max' => '85'];

        Livewire::actingAs($admin)->test(AllureLevels::class)
            ->set('rows', [['label' => 'X', 'targets' => ['5k' => $t, '10k' => $t, 'semi' => $t, 'marathon' => $t]]])
            ->call('save')
            ->assertHasErrors();
        $this->assertSame(0, AllureLevel::count());
    }

    public function test_seul_l_admin_gere_zones_et_table(): void
    {
        foreach ([User::factory()->create(), User::factory()->coach()->create()] as $user) {
            Livewire::actingAs($user)->test(AllureLevels::class)->assertForbidden();
            Livewire::actingAs($user)->test(CatalogueManager::class, ['type' => 'allure_zone'])->assertForbidden();
        }
    }

    public function test_referentiel_de_discipline_reglable_et_borne(): void
    {
        $admin = User::factory()->admin()->create();
        $d = Discipline::create(['label' => 'Trail', 'sort_order' => 9]);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'discipline'])
            ->call('startEdit', $d->id)
            ->set('form.referentiel', 'natation')
            ->call('saveRow')
            ->assertHasErrors('form.referentiel');

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'discipline'])
            ->call('startEdit', $d->id)
            ->set('form.referentiel', 'course')
            ->call('saveRow')
            ->assertHasNoErrors();
        $this->assertSame(Referentiel::Course, $d->refresh()->referentielEnum());

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'discipline'])
            ->call('startEdit', $d->id)
            ->set('form.referentiel', '')
            ->call('saveRow');
        $this->assertNull($d->refresh()->referentiel);
    }

    public function test_seed_rattache_la_course_et_pose_la_grille_generique_une_seule_fois(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->assertSame('course', Discipline::where('label', 'Course à pied')->value('referentiel'));
        $this->assertSame(['Z1', 'Z2', 'Z3', 'Z4', 'Z5'], AllureZone::activeFor(Referentiel::Course)->pluck('code')->all());
        $this->assertSame('SV2', AllureZone::where('code', 'Z3')->value('aliases'));
        $this->assertSame([AllureLevel::MODEL_RIEGEL], AllureLevel::pluck('model')->all());

        // Le coach remplace la grille ; rejouer le seed ne la recrée pas.
        AllureZone::query()->delete();
        $this->zone('EF', 60, 75);
        $this->seed(CatalogSeeder::class);
        $this->assertSame(['EF'], AllureZone::activeFor(Referentiel::Course)->pluck('code')->all());
    }
}
