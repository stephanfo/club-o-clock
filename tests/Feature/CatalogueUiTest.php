<?php

namespace Tests\Feature;

use App\Livewire\Admin\CatalogueManager;
use App\Livewire\Admin\ClubSettingsForm;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\QuotaTag;
use App\Models\User;
use App\Support\ClubPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// UI Paramètres + catalogues J6.1 (PRD §4.17, §4.6) : édition club, CRUD catalogue, garde admin-only.
class CatalogueUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_updates_club_settings(): void
    {
        $admin = User::factory()->admin()->create();

        // Le nom de l'app vient de config('app.name'), plus un réglage éditable ici : on
        // vérifie les réglages qui restent pilotés par le formulaire (fuseau, lien d'invitation).
        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->set('timezone', 'Europe/Paris')
            ->set('invitation_link_days', 21)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(21, ClubSettings::current()->invitation_link_days);
    }

    public function test_color_pickers_are_prefilled_with_starter_palette_but_not_persisted(): void
    {
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(ClubSettingsForm::class);

        // Affichage seulement : un club neuf n'a rien personnalisé (colonnes NULL), mais les color
        // pickers ne peuvent pas rester vides (input type="color") — ils affichent la palette de
        // démarrage réelle plutôt qu'un noir trompeur.
        $this->assertSame(ClubPalette::DEFAULTS['primary_color'], $component->get('primary_color'));
        $this->assertSame(ClubPalette::DEFAULTS['accent_color'], $component->get('accent_color'));
        $this->assertSame(ClubPalette::DEFAULTS['info_color'], $component->get('info_color'));

        $component->call('save')->assertHasNoErrors();

        // Un save sans déviation du défaut ne doit pas figer la palette neutre en base : la
        // distinction « personnalisé vs par défaut » doit rester possible pour ClubPalette.
        $settings = ClubSettings::current();
        $this->assertNull($settings->primary_color);
        $this->assertNull($settings->accent_color);
        $this->assertNull($settings->info_color);
    }

    public function test_custom_color_is_persisted_when_it_deviates_from_default(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->set('primary_color', '#123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('#123456', ClubSettings::current()->primary_color);
    }

    public function test_settings_validate_timezone_and_days(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->set('timezone', 'Mars/Phobos')
            ->set('invitation_link_days', 0)
            ->call('save')
            ->assertHasErrors(['timezone', 'invitation_link_days']);
    }

    public function test_admin_adds_catalogue_entry_via_ui(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'qualification'])
            ->call('startAdd')
            ->set('form.label', 'Brevet fédéral 5')
            ->set('form.code', 'BF5')
            ->call('saveRow')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('qualifications', ['label' => 'Brevet fédéral 5', 'code' => 'BF5']);
    }

    public function test_category_age_bounds_validated(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'category'])
            ->call('startAdd')
            ->set('form.label', 'Bancale')
            ->set('form.age_min', 40)
            ->set('form.age_max', 20) // max < min → erreur gte
            ->call('saveRow')
            ->assertHasErrors(['form.age_max']);
    }

    public function test_quota_tag_code_must_be_unique(): void
    {
        $admin = User::factory()->admin()->create();
        QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'quota_tag'])
            ->call('startAdd')
            ->set('form.label', 'Bassin')
            ->set('form.code', 'piscine') // doublon
            ->set('form.max_per_week', 2)
            ->call('saveRow')
            ->assertHasErrors(['form.code']);
    }

    public function test_editing_quota_tag_keeps_own_code(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = QuotaTag::create(['code' => 'cap', 'label' => 'CAP', 'max_per_week' => 3]);

        // Rééditer la même ligne sans changer le code ne doit pas déclencher l'unicité sur soi-même.
        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'quota_tag'])
            ->call('startEdit', $tag->id)
            ->set('form.label', 'Course à pied')
            ->call('saveRow')
            ->assertHasNoErrors();

        $this->assertSame('Course à pied', $tag->fresh()->label);
    }

    public function test_archive_then_restore_via_ui(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'quota_tag'])
            ->call('archive', $tag->id);
        $this->assertNotNull($tag->fresh()->archived_at);

        Livewire::actingAs($admin)->test(CatalogueManager::class, ['type' => 'quota_tag'])
            ->call('restore', $tag->id);
        $this->assertNull($tag->fresh()->archived_at);
    }

    public function test_unknown_catalogue_type_404s(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.catalogues', 'bidon'))->assertNotFound();
    }

    public function test_non_admin_cannot_access_settings_or_catalogues(): void
    {
        $coach = User::factory()->coach()->create();
        Discipline::firstOrCreate(['label' => 'Natation']);

        $this->actingAs($coach)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($coach)->get(route('admin.catalogues', 'discipline'))->assertForbidden();
    }

    public function test_admin_can_access_settings_and_catalogues(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        $this->actingAs($admin)->get(route('admin.catalogues', 'discipline'))->assertOk();
    }
}
