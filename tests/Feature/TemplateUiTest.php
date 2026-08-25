<?php

namespace Tests\Feature;

use App\Livewire\Admin\TemplateForm;
use App\Livewire\Admin\TemplateList;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\TemplateGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// UI Modèles de génération J5 (PRD §4.8) : CRUD admin, génération à l'enregistrement, archivage,
// relance, garde admin-only.
class TemplateUiTest extends TestCase
{
    use RefreshDatabase;

    private function discipline(): Discipline
    {
        return Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
    }

    public function test_admin_creates_template_and_generates_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('label', 'Natation seuil')
            ->set('discipline_id', $disc->id)
            ->set('day_of_week', 1) // lundi
            ->set('start_time_of_day', '19:00')
            ->set('generation_start_date', '2026-09-01')
            ->set('generation_end_date', '2026-09-30')
            ->call('save')
            ->assertRedirect(route('admin.templates'));

        $tpl = SessionTemplate::first();
        $this->assertNotNull($tpl);
        $this->assertSame('Natation seuil', $tpl->label);
        $this->assertSame($admin->id, $tpl->created_by);
        // Génération immédiate à l'enregistrement : 4 lundis de septembre (§4.8).
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());
    }

    public function test_occurrence_preview_is_live(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('day_of_week', 1)
            ->set('generation_start_date', '2026-09-01')
            ->set('generation_end_date', '2026-09-30')
            ->assertSet('occurrenceCount', 4) // propriété calculée live
            ->set('generation_end_date', '2026-09-07')
            ->assertSet('occurrenceCount', 1);
    }

    public function test_past_occurrences_are_flagged_in_preview(): void
    {
        $admin = User::factory()->admin()->create();

        // Plage à cheval sur aujourd'hui : des lundis passés + des futurs.
        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('day_of_week', 1)
            ->set('generation_start_date', now()->subWeeks(3)->toDateString())
            ->set('generation_end_date', now()->addWeeks(3)->toDateString())
            ->assertSet('pastCount', fn ($v) => $v >= 1);
    }

    public function test_end_date_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('label', 'X')
            ->set('discipline_id', $disc->id)
            ->set('generation_start_date', '2026-09-01')
            ->set('generation_end_date', '')
            ->call('save')
            ->assertHasErrors(['generation_end_date' => 'required']);
    }

    public function test_editing_template_does_not_regenerate(): void
    {
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1, 'discipline_id' => $disc->id,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);
        // 4 séances déjà générées au départ.
        app(TemplateGenerationService::class)->generate($tpl, $admin);
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());

        Livewire::actingAs($admin)->test(TemplateForm::class, ['template' => $tpl])
            ->set('label', 'Renommé')
            ->call('save')
            ->assertRedirect(route('admin.templates'));

        // L'édition met à jour le label SANS re-propager (§4.8).
        $this->assertSame('Renommé', $tpl->fresh()->label);
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());
    }

    // Double-tap sur « Générer & enregistrer » : sans wire:loading le second clic partait avant
    // le retour du premier, et generate() créant les Session sans déduplication, la plage était
    // générée deux fois. Les séances en double sont persistantes (§4.8).
    public function test_double_tap_on_generate_does_not_duplicate_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1, 'discipline_id' => $disc->id,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);

        // Deux appels consécutifs, comme un double-tap sur le mutualisé.
        Livewire::actingAs($admin)->test(TemplateList::class)
            ->call('generate', $tpl->id)
            ->call('generate', $tpl->id);

        // 4 lundis en septembre 2026 : la seconde passe ne doit rien ajouter.
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());
    }

    // Revue de code — l'idempotence comparait l'instant EXACT : une séance décalée par le bureau
    // (créneau de piscine changé) ne correspondait plus à son créneau d'origine et y était
    // recréée. On compare désormais le jour local.
    public function test_regenerating_does_not_duplicate_a_rescheduled_session(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);
        app(TemplateGenerationService::class)->generate($tpl, $admin);

        // Le bureau décale une séance d'une heure.
        $moved = Session::where('source_template_id', $tpl->id)->orderBy('start_at')->first();
        $moved->forceFill(['start_at' => $moved->start_at->copy()->addHour()])->save();

        app(TemplateGenerationService::class)->generate($tpl, $admin);

        // Toujours 4 lundis : la séance déplacée reste l'occurrence de son jour.
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());
    }

    public function test_archive_and_reactivate(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create(['created_by' => $admin->id]);

        Livewire::actingAs($admin)->test(TemplateList::class)
            ->call('archive', $tpl->id);
        $this->assertSame('archived', $tpl->fresh()->status);

        Livewire::actingAs($admin)->test(TemplateList::class)
            ->call('reactivate', $tpl->id);
        $this->assertSame('active', $tpl->fresh()->status);
    }

    public function test_relaunch_generates_on_new_range_without_erasing(): void
    {
        $admin = User::factory()->admin()->create();
        $tpl = SessionTemplate::factory()->create([
            'created_by' => $admin->id, 'day_of_week' => 1,
            'generation_start_date' => '2026-09-01', 'generation_end_date' => '2026-09-30',
        ]);
        app(TemplateGenerationService::class)->generate($tpl, $admin); // 4 septembre

        Livewire::actingAs($admin)->test(TemplateList::class)
            ->call('openRelaunch', $tpl->id)
            ->set('relaunchStart', '2026-10-01')
            ->set('relaunchEnd', '2026-10-31')
            ->assertSet('relaunchCount', 4)
            ->call('relaunch')
            ->assertSet('relaunchId', null);

        $this->assertSame(8, Session::where('source_template_id', $tpl->id)->count());
    }

    public function test_capacity_left_empty_creates_unlimited_sessions(): void
    {
        // Régression : le défaut du formulaire valait 16, si bien qu'un modèle « sans limite »
        // était impossible à créer sans vider le champ — et le vider ne suffisait pas (cf. test
        // suivant). Capacité absente = illimitée (§4.10), sur le modèle comme sur ses séances.
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->assertSet('capacity', null) // aucune capacité pré-remplie
            ->set('label', 'Sortie club')
            ->set('discipline_id', $disc->id)
            ->set('day_of_week', 1)
            ->set('start_time_of_day', '19:00')
            ->set('generation_start_date', '2026-09-01')
            ->set('generation_end_date', '2026-09-30')
            ->call('save');

        $tpl = SessionTemplate::first();
        $this->assertNull($tpl->capacity);
        // La génération recopie la valeur telle quelle : aucune séance ne reçoit de limite.
        $this->assertSame(4, Session::where('source_template_id', $tpl->id)->count());
        $this->assertSame(0, Session::where('source_template_id', $tpl->id)->whereNotNull('capacity')->count());
    }

    public function test_emptied_capacity_survives_a_server_roundtrip(): void
    {
        // Régression : le champ étant en wire:model deferred, vider la capacité puis cliquer une
        // catégorie (aller-retour serveur) re-rendait la vue depuis l'état serveur et réaffichait
        // la valeur d'origine — la saisie était perdue en silence. Le binding est désormais .blur.
        $admin = User::factory()->admin()->create();
        $disc = $this->discipline();
        $cat = Category::create(['label' => 'Poussins', 'age_min' => 10, 'age_max' => 11, 'sort_order' => 1]);

        Livewire::actingAs($admin)->test(TemplateForm::class)
            ->set('label', 'Sortie club')
            ->set('discipline_id', $disc->id)
            ->set('day_of_week', 1)
            ->set('start_time_of_day', '19:00')
            ->set('generation_start_date', '2026-09-01')
            ->set('generation_end_date', '2026-09-30')
            ->set('capacity', 12)
            ->set('capacity', null)      // l'utilisateur efface la capacité…
            ->call('toggleCategory', $cat->id) // …puis clique une catégorie : aller-retour serveur
            ->assertSet('capacity', null)      // la saisie ne doit pas avoir été écrasée
            ->call('save');

        $this->assertNull(SessionTemplate::first()->capacity);
    }

    public function test_non_admin_cannot_access_templates(): void
    {
        $coach = User::factory()->coach()->create();

        // Garde admin-only (SessionTemplatePolicy) via la route — un coach reçoit 403.
        $this->actingAs($coach)->get(route('admin.templates'))->assertForbidden();
        $this->actingAs($coach)->get(route('admin.templates.create'))->assertForbidden();
    }

    public function test_admin_can_access_templates(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.templates'))->assertOk();
        $this->actingAs($admin)->get(route('admin.templates.create'))->assertOk();
    }
}
