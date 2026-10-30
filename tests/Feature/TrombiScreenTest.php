<?php

namespace Tests\Feature;

use App\Livewire\Trombinoscope;
use App\Livewire\TrombiShow;
use App\Models\Category;
use App\Models\Qualification;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\TrombiService;
use App\Support\Trombi\BoardRole;
use App\Support\Trombi\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Écrans et règles de visibilité du trombinoscope (#72, PRD §4.23.3 à §4.23.6).
class TrombiScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->viewer = User::factory()->create(['first_name' => 'Lecteur', 'last_name' => 'Lambda']);
    }

    private function grant(User $u, ConsentType ...$types): void
    {
        foreach ($types as $t) {
            app(ConsentService::class)->grant($u, $t, $u->isLegallyMinor() ? $u->guardian : $u);
        }
    }

    private function photo(User $u, ?User $actor = null): void
    {
        $img = imagecreatetruecolor(50, 50);
        $file = tempnam(sys_get_temp_dir(), 'trombi');
        imagejpeg($img, $file);
        app(TrombiService::class)->storePhoto($u, $file, $actor ?? $u);
    }

    public function test_qui_consulte(): void
    {
        $suspended = User::factory()->create(['athlete_access_suspended' => true]);
        $noRole = User::factory()->create(['roles' => []]);
        $parent = User::factory()->create(['roles' => []]);
        User::factory()->minorP1()->create(['guardian_id' => $parent->id]);

        $this->actingAs($this->viewer)->get(route('trombi'))->assertOk();
        $this->actingAs(User::factory()->coach()->create(['athlete_access_suspended' => true]))->get(route('trombi'))->assertOk();
        $this->actingAs($parent)->get(route('trombi'))->assertOk();
        $this->actingAs($suspended)->get(route('trombi'))->assertForbidden();
        $this->actingAs($noRole)->get(route('trombi'))->assertForbidden();
    }

    public function test_qui_figure(): void
    {
        User::factory()->create(['first_name' => 'Suspendue', 'athlete_access_suspended' => true]);
        User::factory()->create(['first_name' => 'Inactive', 'is_active' => false]);
        User::factory()->create(['first_name' => 'Sansrole', 'roles' => []]);
        User::factory()->coach()->create(['first_name' => 'Coachsuspendu', 'athlete_access_suspended' => true]);
        User::factory()->create(['first_name' => 'Figurante']);

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->assertSee('Figurante')->assertSee('Coachsuspendu')
            ->assertDontSee('Suspendue')->assertDontSee('Inactive')->assertDontSee('Sansrole');
    }

    public function test_sans_accord_prenom_et_initiale_coachs_et_bureau_en_entier(): void
    {
        User::factory()->create(['first_name' => 'Marie', 'last_name' => 'Zyxwabcd']);
        $consenting = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Qwertzuiop']);
        $this->grant($consenting, ConsentType::FullName);
        User::factory()->coach()->create(['first_name' => 'Vincent', 'last_name' => 'Coachnom']);
        $board = User::factory()->create(['first_name' => 'Anne', 'last_name' => 'Tresornom']);
        $board->forceFill(['board_role' => BoardRole::Treasurer])->save();

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->assertSee('Marie Z.')->assertDontSee('Zyxwabcd')
            ->assertSee('Paul Qwertzuiop')
            ->assertSee('Vincent Coachnom')->assertSee('Coach')
            ->assertSee('Anne Tresornom')->assertSee('Trésorier·e');
    }

    public function test_la_recherche_ne_porte_que_sur_le_nom_affiche(): void
    {
        User::factory()->create(['first_name' => 'Marie', 'last_name' => 'Zyxwabcd']);

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->set('q', 'zyxw')->assertDontSee('Marie Z.')
            ->set('q', 'mari')->assertSee('Marie Z.');
    }

    public function test_categorie_sous_accord_ou_pour_coachs_et_admins(): void
    {
        $cat = Category::create(['label' => 'Vétéran 1', 'age_min' => 40, 'age_max' => 49]);
        $hidden = User::factory()->create(['first_name' => 'Cachee']);
        $hidden->categories()->attach($cat->id, ['is_primary' => true]);

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->assertSee('Cachee')->assertDontSee('Vétéran 1');
        Livewire::actingAs(User::factory()->coach()->create())->test(Trombinoscope::class)
            ->assertSee('Vétéran 1');

        $this->grant($hidden, ConsentType::Category);
        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->assertSee('Vétéran 1')
            ->call('setCategory', 'Vétéran 1')->assertSee('Cachee')->assertDontSee('Lecteur');
    }

    public function test_qualifications_en_cours_de_validite_seules(): void
    {
        $coach = User::factory()->coach()->create(['first_name' => 'Vincent']);
        $ok = Qualification::create(['code' => 'BF5', 'label' => 'Brevet fédéral 5']);
        $old = Qualification::create(['code' => 'PSC', 'label' => 'Secourisme PSC1']);
        $coach->qualifications()->attach($ok->id, ['attributed_at' => now(), 'expires_at' => null]);
        $coach->qualifications()->attach($old->id, ['attributed_at' => now()->subYears(3), 'expires_at' => now()->subDay()->toDateString()]);

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->assertSee('Brevet fédéral 5')->assertDontSee('Secourisme PSC1');
    }

    public function test_coordonnees_sous_accord_jamais_pour_un_lecteur_mineur(): void
    {
        $u = User::factory()->create(['email' => 'paul@exemple.test']);
        app(TrombiService::class)->setPhone($u, '0612345678', $u);
        $silent = User::factory()->create(['email' => 'silence@exemple.test']);
        app(TrombiService::class)->setPhone($silent, '0698765432', $silent);
        $this->grant($u, ConsentType::Email, ConsentType::Phone);

        $this->actingAs($this->viewer)->get(route('trombi.show', $u))
            ->assertOk()->assertSee('mailto:paul@exemple.test', false)->assertSee('tel:+33612345678', false)->assertSee('06 12 34 56 78');
        $this->actingAs($this->viewer)->get(route('trombi.show', $silent))
            ->assertOk()->assertDontSee('silence@exemple.test')->assertDontSee('0698765432')->assertDontSee('98 76 54 32');

        $parent = User::factory()->create();
        $ado = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'email' => 'ado@exemple.test', 'email_verified_at' => now()]);
        $this->actingAs($ado)->get(route('trombi.show', $u))
            ->assertOk()->assertDontSee('paul@exemple.test')->assertDontSee('tel:', false);
    }

    public function test_contact_du_parent_sur_la_fiche_de_l_enfant(): void
    {
        $parent = User::factory()->create(['roles' => [], 'first_name' => 'Claire', 'email' => 'claire@exemple.test']);
        $child = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'first_name' => 'Lou']);

        $this->actingAs($this->viewer)->get(route('trombi.show', $child))->assertOk()->assertDontSee('Parent :');

        $this->grant($parent, ConsentType::Email);
        $this->actingAs($this->viewer)->get(route('trombi.show', $child))
            ->assertSee('Parent : Claire')->assertSee('mailto:claire@exemple.test', false);
    }

    public function test_anniversaires_du_mois_sous_accord(): void
    {
        $month = Carbon::now()->month;
        $dob = Carbon::now()->startOfMonth()->addDays(4)->subYears(30)->toDateString();
        $shared = User::factory()->create(['first_name' => 'Fetee', 'dob' => $dob]);
        User::factory()->create(['first_name' => 'Discrete', 'dob' => $dob]);
        $this->grant($shared, ConsentType::Birthday);

        Livewire::actingAs($this->viewer)->test(Trombinoscope::class)
            ->call('setView', 'anniversaires')
            ->assertSee('Fetee')->assertDontSee('Discrete')
            ->assertDontSee((string) Carbon::parse($dob)->year)
            ->call('shiftMonth', 1)->assertDontSee('Fetee');
        $this->assertSame($month, Carbon::parse($dob)->month);
    }

    public function test_fiche_d_un_non_adherent_introuvable(): void
    {
        $gone = User::factory()->create(['athlete_access_suspended' => true]);

        $this->actingAs($this->viewer)->get(route('trombi.show', $gone))->assertNotFound();
        $this->actingAs($gone)->get(route('trombi.show', $gone))->assertForbidden();
        Livewire::actingAs($this->viewer)->test(TrombiShow::class, ['user' => $this->viewer])->assertSee('Modifier ma carte');
    }

    public function test_route_photo(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->photo($u);
        $url = route('trombi.photo', ['user' => $u->id, 'size' => 192]);

        // Sans accord : visible de l'intéressé seul, ni des autres, ni de l'admin.
        $this->actingAs($u)->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($this->viewer)->get($url)->assertNotFound();
        $this->actingAs($admin)->get($url)->assertNotFound();

        $this->grant($u, ConsentType::Photo);
        $response = $this->actingAs($this->viewer)->get($url)->assertOk();
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $etag = $response->headers->get('ETag');
        $this->actingAs($this->viewer)->get($url, ['If-None-Match' => $etag])->assertStatus(304);

        // Lecteur hors saison, taille inconnue.
        $this->actingAs(User::factory()->create(['athlete_access_suspended' => true]))->get($url)->assertNotFound();
        $this->actingAs($this->viewer)->get(route('trombi.photo', ['user' => $u->id, 'size' => 192]).'x')->assertNotFound();

        // Retrait de l'accord : effet au chargement suivant.
        app(ConsentService::class)->revoke($u, ConsentType::Photo, $u);
        $this->actingAs($this->viewer)->get($url, ['If-None-Match' => $etag])->assertNotFound();
    }

    public function test_photo_d_un_enfant_visible_du_parent_meme_masquee(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $child = User::factory()->minorP1()->create(['guardian_id' => $parent->id]);
        $this->photo($child, $parent);

        $this->actingAs($parent)->get(route('trombi.photo', ['user' => $child->id, 'size' => 512]))->assertOk();
        $this->actingAs($this->viewer)->get(route('trombi.photo', ['user' => $child->id, 'size' => 512]))->assertNotFound();
    }
}
