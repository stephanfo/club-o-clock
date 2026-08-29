<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberCreate;
use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\MemberShow;
use App\Models\Category;
use App\Models\User;
use App\Support\AgeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// UI Adhérents J6.2 (PRD §4.17.1, §4.1.3) : garde admin-only, liste filtrable, fiche éditable, création.
class MemberUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden_on_all_member_screens(): void
    {
        $athlete = User::factory()->create(['roles' => ['athlete']]);
        $target = User::factory()->create();

        $this->actingAs($athlete)->get(route('admin.members'))->assertForbidden();
        $this->actingAs($athlete)->get(route('admin.members.create'))->assertForbidden();
        $this->actingAs($athlete)->get(route('admin.members.show', $target))->assertForbidden();
    }

    public function test_list_search_and_access_filter(): void
    {
        $admin = User::factory()->admin()->create(['first_name' => 'Admin', 'last_name' => 'Bureau']);
        User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Durand', 'athlete_access_suspended' => false]);
        User::factory()->create(['first_name' => 'Léo', 'last_name' => 'Martin', 'athlete_access_suspended' => true]);

        Livewire::actingAs($admin)->test(MemberList::class)
            ->set('search', 'Durand')
            ->assertSee('Camille Durand')
            ->assertDontSee('Léo Martin')
            ->set('search', '')
            ->call('setAccess', 'suspended')
            ->assertSee('Léo Martin')
            ->assertDontSee('Camille Durand');
    }

    /**
     * Le bloc « Pupilles » affiche la catégorie d'âge de chaque enfant : $ward->primaryCategory()
     * lit $ward->categories, qui n'était pas eager-loadé. Sous preventLazyLoading, la fiche d'un
     * parent garant plantait — et seulement celle-là, d'où le défaut passé inaperçu.
     */
    public function test_show_of_a_guardian_renders_its_wards_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::create(['label' => 'Benjamins', 'age_min' => 12, 'age_max' => 13, 'sort_order' => 1]);

        $parent = User::factory()->create(['dob' => '1982-06-25']);
        $ward = User::factory()->create(['first_name' => 'Jade', 'dob' => '2013-04-02', 'guardian_id' => $parent->id]);
        $ward->categories()->attach($cat->id, ['is_primary' => true]);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $parent])
            ->assertOk()
            ->assertSee('Jade')
            ->assertSee('Benjamins');
    }

    public function test_show_toggle_role_persists_and_toasts(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['roles' => ['athlete']]);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('toggleRole', 'coach')
            ->assertSee('Rôle coach ajouté');

        $this->assertContains('coach', $member->fresh()->roles);
    }

    // C3 — « Aucune catégorie active ne couvre cet âge » s'affichait dès que la catégorie principale
    // était absente, y compris sans date de naissance (le libellé parle d'âge) et sur un pivot
    // périmé. Trois causes distinctes, trois messages.
    public function test_show_distinguishes_the_three_causes_of_a_missing_category(): void
    {
        $admin = User::factory()->admin()->create();
        Category::create(['label' => 'Master', 'age_min' => 40, 'age_max' => 120, 'sort_order' => 1]);

        // 1. Pas de date de naissance : ne rien dire de l'âge.
        $sansDob = User::factory()->create(['dob' => null, 'roles' => ['coach']]);
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $sansDob])
            ->assertSee('Aucune date de naissance saisie', escape: false)
            ->assertDontSee('Aucune catégorie active ne couvre cet âge', escape: false);

        // 2. Pivot périmé : l'âge EST couvert, mais rien n'est rattaché (cas mathieu@demo.club).
        $pivotPerime = User::factory()->create(['dob' => '1986-03-14', 'roles' => ['athlete']]);
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $pivotPerime])
            ->assertSee('attendue mais non rattachée', escape: false)
            ->assertDontSee('Aucune catégorie active ne couvre cet âge', escape: false);

        // 3. Coach-pur AVEC dob : état parfaitement normal, surtout pas une incohérence à signaler.
        $coachPur = User::factory()->create(['dob' => '1980-03-14', 'roles' => ['coach']]);
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $coachPur])
            ->assertDontSee('attendue mais non rattachée', escape: false)
            ->assertDontSee('Aucune catégorie active ne couvre cet âge', escape: false)
            ->assertSee('Compte sans rôle athlète', escape: false);

        // 4. Vrai trou de barème : aucune catégorie active ne couvre cet âge → message d'origine.
        $horsBareme = User::factory()->create(['dob' => '2020-01-01', 'roles' => ['athlete']]);
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $horsBareme])
            ->assertSee('Aucune catégorie active ne couvre cet âge', escape: false);
    }

    public function test_show_edit_dob_recomputes_primary_category_and_keeps_surclassement(): void
    {
        $admin = User::factory()->admin()->create();
        // Catégories d'âge disjointes + une catégorie « surclassement » manuelle (Élite, hors dérivation).
        $minimes = Category::create(['label' => 'Minimes', 'age_min' => 14, 'age_max' => 15, 'sort_order' => 1]);
        $adulte = Category::create(['label' => 'Adulte', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 2]);
        $elite = Category::create(['label' => 'Élite', 'age_min' => 90, 'age_max' => 99, 'sort_order' => 3]);

        // Adhérent adulte (principale dérivée = Adulte) + surclassement manuel Élite (is_primary=false).
        $member = User::factory()->create(['dob' => '1990-05-10']);
        $member->categories()->sync([
            $adulte->id => ['is_primary' => true],
            $elite->id => ['is_primary' => false],
        ]);

        // DOB déterministe ciblant seasonAge=15 (réf = 31/08 fin de saison) — robuste quel que soit le mois courant.
        $now = now();
        $endYear = ($now->month >= 9 ? $now->year : $now->year - 1) + 1;
        $reference = Carbon::create($endYear, 8, 31);
        $newDob = $reference->copy()->subYears(15)->subMonths(1)->toDateString();
        $this->assertSame(15, AgeCategory::seasonAge(Carbon::parse($newDob)));

        // Correction : devient mineur → principale dérivée bascule sur Minimes, surclassement Adulte conservé.
        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('editDob')
            ->set('dob', $newDob)
            ->call('saveDob')
            ->assertHasNoErrors()
            ->assertSet('editingDob', false)
            ->assertSee('Date de naissance mise à jour');

        $member->refresh()->load('categories');
        $this->assertTrue($member->is_minor);
        // Principale dérivée bascule Adulte → Minimes (l'ancienne dérivée n'est pas un surclassement, elle part).
        $this->assertSame($minimes->id, $member->primaryCategory()?->id);
        $this->assertFalse($member->categories->contains('id', $adulte->id));
        // Surclassement manuel Élite préservé (is_primary=false).
        $this->assertTrue($member->categories->contains(
            fn ($c) => $c->id === $elite->id && ! $c->pivot->is_primary
        ));
    }

    public function test_show_edit_dob_rejects_future_date(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['dob' => '1990-05-10']);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('editDob')
            ->set('dob', now()->addDay()->toDateString())
            ->call('saveDob')
            ->assertHasErrors('dob');

        $this->assertSame('1990-05-10', $member->fresh()->dob->toDateString());
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(MemberCreate::class)
            ->set('first_name', '')
            ->call('create')
            ->assertHasErrors(['first_name', 'last_name', 'dob']);
    }

    public function test_create_persists_adult_member(): void
    {
        $admin = User::factory()->admin()->create();
        Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 1]);

        Livewire::actingAs($admin)->test(MemberCreate::class)
            ->set('first_name', 'Camille')
            ->set('last_name', 'Vincent')
            ->set('dob', '2000-01-01')
            ->set('email', 'camille.vincent@example.test')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'first_name' => 'Camille',
            'last_name' => 'Vincent',
            'email' => 'camille.vincent@example.test',
            'is_minor' => false,
        ]);
    }

    public function test_minor_p1_requires_no_email(): void
    {
        $admin = User::factory()->admin()->create();
        Category::create(['label' => 'Minimes', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 1]);

        Livewire::actingAs($admin)->test(MemberCreate::class)
            ->set('first_name', 'Léo')
            ->set('last_name', 'Martin')
            ->set('dob', '2012-01-01') // mineur
            ->set('phase', 'P1')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['first_name' => 'Léo', 'is_minor' => true, 'email' => null]);
    }
}
