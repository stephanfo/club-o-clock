<?php

namespace Tests\Feature;

use App\Livewire\TrombiSettings;
use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use App\Support\Trombi\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Réglages du trombinoscope : onglet du profil et page d'un enfant (#72, PRD §4.23.6, §4.23.4).
class TrombiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_onglet_du_profil(): void
    {
        $u = User::factory()->create();

        $this->actingAs($u)->get(route('profil', ['tab' => 'trombi']))
            ->assertOk()->assertSee('Ce que voient les adhérents')->assertSee('Trombinoscope du club');
    }

    public function test_accords_un_par_un_et_tout(): void
    {
        $u = User::factory()->create();
        $c = Livewire::actingAs($u)->test(TrombiSettings::class, ['subjectId' => $u->id]);

        $c->call('toggleConsent', ConsentType::Photo->value);
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Photo));

        // Depuis l'état mixte, « Tout » active tout ce qui s'applique (pas le téléphone sans numéro).
        $c->call('toggleAll');
        $fresh = $u->fresh();
        $this->assertTrue($fresh->hasConsent(ConsentType::Birthday));
        $this->assertFalse($fresh->hasConsent(ConsentType::Phone));

        $c->call('toggleAll');
        $this->assertSame(0, Consent::query()->whereNull('revoked_at')->count());

        $c->call('toggleConsent', ConsentType::Phone->value)->assertSee('Saisis d\'abord le numéro à partager.');
        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Phone));

        $c->set('phone', '06 12 34 56 78')->call('savePhone')
            ->assertSet('phone', '06 12 34 56 78')
            ->call('toggleConsent', ConsentType::Phone->value);
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Phone));
    }

    public function test_depot_de_photo_sans_garder_l_envoi(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs($u)->test(TrombiSettings::class, ['subjectId' => $u->id])
            ->set('photo', UploadedFile::fake()->image('moi.jpg', 300, 400))
            ->assertHasNoErrors();

        $this->assertNotNull($u->fresh()->photo_path);
        $this->assertCount(2, Storage::disk('local')->allFiles('trombi'));
        $this->assertSame([], Storage::disk('local')->allFiles('livewire-tmp'), 'L\'envoi temporaire est effacé.');

        Livewire::actingAs($u)->test(TrombiSettings::class, ['subjectId' => $u->id])
            ->set('photo', UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'))
            ->assertHasErrors('photo');
    }

    public function test_pas_de_reglages_pour_un_tiers(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(TrombiSettings::class, ['subjectId' => $u->id])->assertForbidden();
        Livewire::actingAs(User::factory()->admin()->create())->test(TrombiSettings::class, ['subjectId' => $u->id])->assertForbidden();
    }

    public function test_l_ado_retire_mais_n_accorde_pas(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $ado = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'email' => 'ado@exemple.test', 'email_verified_at' => now()]);
        app(ConsentService::class)->grant($ado, ConsentType::Photo, $parent);

        $c = Livewire::actingAs($ado)->test(TrombiSettings::class, ['subjectId' => $ado->id])
            ->assertSee('Ton parent garant décide')
            ->assertDontSee('Numéro à partager');

        $c->call('toggleConsent', ConsentType::Birthday->value);
        $this->assertFalse($ado->fresh()->hasConsent(ConsentType::Birthday));

        $c->call('toggleAll');
        $this->assertFalse($ado->fresh()->hasConsent(ConsentType::Photo), '« Tout » éteint pour qui ne peut que retirer.');
    }

    public function test_le_parent_regle_son_enfant(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $child = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'first_name' => 'Lou']);

        $this->actingAs($parent)->get(route('children.trombi', $child))->assertOk()->assertSee('Trombinoscope de Lou');
        $this->actingAs(User::factory()->create())->get(route('children.trombi', $child))->assertForbidden();
        $this->actingAs($parent)->get(route('children'))->assertSee(route('children.trombi', $child), false);

        Livewire::actingAs($parent)->test(TrombiSettings::class, ['subjectId' => $child->id])
            ->set('photo', UploadedFile::fake()->image('lou.png', 200, 200))
            ->call('toggleAll')
            ->assertDontSee('Numéro à partager');

        $fresh = $child->fresh();
        $this->assertNotNull($fresh->photo_path);
        $this->assertTrue($fresh->hasConsent(ConsentType::Photo));
        $this->assertFalse($fresh->hasConsent(ConsentType::Email));
        $this->assertSame($parent->id, Consent::query()->where('type', ConsentType::Photo->value)->value('granted_by'));
    }
}
