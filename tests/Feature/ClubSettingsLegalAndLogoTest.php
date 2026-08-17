<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClubSettingsForm;
use App\Models\ClubSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Revue open source 2026-08-08 — écran « Paramètres du club » :
//   n°1  aucun document exécutable (SVG) ne doit atteindre le disque public, servi same-origin ;
//   n°8  aperçu du logo appelé avant validation → 500 sur un type non affichable ;
//   n°11 mentions légales saisies en admin plutôt qu'écrites dans la vue publique ;
//   n°6  libellé d'année sportive dérivé du mois de bascule, jamais figé.
//
// Sur le n°1 : la règle `image` de Laravel 13 exclut DÉJÀ le SVG (validateImage n'ajoute 'svg' aux
// mimes autorisés que sur le paramètre `allow_svg`). Le refus n'était donc pas absent, mais
// implicite — il tenait au comportement par défaut d'une règle du framework, sans rien dans le code
// qui dise que le SVG est proscrit ni pourquoi. Ces tests verrouillent l'invariant pour qu'il
// survive à une évolution du framework ou à un `allow_svg` ajouté sans en mesurer la portée.
class ClubSettingsLegalAndLogoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin'], 'is_active' => true]);
    }

    /** n°1 — le SVG est un document exécutable : la validation doit le refuser. */
    public function test_svg_logo_is_rejected_by_validation(): void
    {
        Storage::fake('public');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', $svg))
            ->assertHasErrors('logo');

        $this->assertNull(ClubSettings::current()->logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('logos'));
    }

    /** n°1 — les formats matriciels attendus restent acceptés (le correctif ne casse pas l'usage). */
    public function test_png_logo_is_accepted(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 300, 300))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(ClubSettings::current()->fresh()->logo_path);
    }

    /**
     * n°8 — un type non affichable est rejeté DÈS le dépôt, sans que l'écran tombe en 500.
     *
     * L'aperçu appelle temporaryUrl() au re-render qui suit l'upload ; Livewire y lève
     * FileNotPreviewableException. `accept="image/*"` ne protège pas (la boîte de dialogue système
     * laisse choisir « Tous les fichiers »).
     */
    public function test_non_previewable_upload_errors_instead_of_crashing_the_screen(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('logo', UploadedFile::fake()->create('dossier.pdf', 40, 'application/pdf'))
            ->assertHasErrors('logo')
            ->assertOk();
    }

    /** n°11 — les mentions légales se saisissent en admin et s'affichent sur la page publique. */
    public function test_legal_fields_are_editable_and_published(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('legal_publisher', 'Association Test, 1 rue des Sports, RNA W123')
            ->set('legal_host', 'Hébergeur Test, Roubaix')
            ->set('legal_director', 'La présidente')
            ->set('legal_contact_email', 'contact@test.fr')
            ->set('legal_mail_provider', 'Fournisseur Test')
            ->set('legal_source_url', 'https://example.org/depot')
            ->call('save')
            ->assertHasNoErrors();

        $this->get(route('legal'))
            ->assertOk()
            ->assertSee('Association Test, 1 rue des Sports, RNA W123')
            ->assertSee('contact@test.fr')
            ->assertSee('https://example.org/depot')
            ->assertDontSee('[À COMPLÉTER PAR LE CLUB]');
    }

    /** n°11 — tant que rien n'est saisi, la page le signale au lieu de faire croire à un texte complet. */
    public function test_legal_page_warns_while_incomplete(): void
    {
        $this->get(route('legal'))
            ->assertOk()
            ->assertSee('[À COMPLÉTER PAR LE CLUB]')
            ->assertSee('Paramètres du club');
    }

    /** n°11 — une URL de code source non http(s) est refusée : elle est rendue en href public. */
    public function test_javascript_source_url_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('legal_source_url', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors('legal_source_url');
    }

    /** n°6 — le libellé d'année sportive suit le mois de bascule au lieu d'annoncer « sept → août ». */
    public function test_season_label_follows_the_configured_start_month(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ClubSettingsForm::class)
            ->set('season_start_month', 1)
            ->assertSee('janv. → déc.')
            ->assertDontSee('Année sportive · sept. → août');
    }
}
