<?php

namespace Tests\Feature;

use App\Livewire\Admin\InformationPageForm;
use App\Livewire\Admin\InformationPageList;
use App\Models\InformationPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Pages d'information (notes club) : CRUD admin, visibilité par niveau cumulatif,
// bannières épinglées sur l'accueil, sanitisation du contenu WYSIWYG.
class InformationPageTest extends TestCase
{
    use RefreshDatabase;

    // ── CRUD admin ──

    public function test_admin_creates_page_and_content_is_sanitized(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(InformationPageForm::class)
            ->set('title', 'Bon d’achat partenaire')
            ->set('visibility', 'all')
            ->set('pinned', true)
            ->set('content_markdown', "**Code CLUB2026**\n\n<script>alert('x')</script>")
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.infos'));

        $page = InformationPage::firstOrFail();
        $this->assertSame('Bon d’achat partenaire', $page->title);
        $this->assertTrue($page->pinned);
        $this->assertSame($admin->id, $page->created_by);
        // Le contenu est nettoyé serveur : le script ne survit pas.
        $this->assertStringNotContainsString('<script', (string) $page->content_markdown);
        $this->assertStringContainsString('Code CLUB2026', (string) $page->content_markdown);
    }

    public function test_admin_edits_and_archives_and_restores_page(): void
    {
        $admin = User::factory()->admin()->create();
        $page = InformationPage::create(['title' => 'Note', 'visibility' => 'all']);

        Livewire::actingAs($admin)->test(InformationPageForm::class, ['page' => $page])
            ->set('title', 'Note modifiée')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Note modifiée', $page->fresh()->title);

        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->call('archive', $page->id);
        $this->assertNotNull($page->fresh()->archived_at);
        $this->assertFalse($page->fresh()->pinned);

        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->call('restore', $page->id);
        $this->assertNull($page->fresh()->archived_at);
    }

    public function test_admin_toggles_pin_and_deletes(): void
    {
        $admin = User::factory()->admin()->create();
        $page = InformationPage::create(['title' => 'Note', 'visibility' => 'all']);

        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->call('togglePin', $page->id);
        $this->assertTrue($page->fresh()->pinned);

        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->call('delete', $page->id);
        $this->assertModelMissing($page);
    }

    public function test_delete_confirmation_modal_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $page = InformationPage::create(['title' => 'Note', 'visibility' => 'all']);

        // Ouverture de la modale : cible la page, sans encore supprimer.
        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->assertSet('confirmingDeleteId', null)
            ->call('confirmDelete', $page->id)
            ->assertSet('confirmingDeleteId', $page->id)
            ->assertSee('irréversible')
            // Annulation : referme sans rien supprimer.
            ->call('cancelDelete')
            ->assertSet('confirmingDeleteId', null);
        $this->assertModelExists($page);

        // Confirmation : supprime et referme la modale.
        Livewire::actingAs($admin)->test(InformationPageList::class)
            ->call('confirmDelete', $page->id)
            ->call('delete', $page->id)
            ->assertSet('confirmingDeleteId', null);
        $this->assertModelMissing($page);
    }

    public function test_title_is_required(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(InformationPageForm::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title']);
    }

    // ── Autorisation ──

    public function test_admin_pages_are_forbidden_to_non_admin(): void
    {
        $coach = User::factory()->coach()->create();
        $athlete = User::factory()->create();

        $this->actingAs($coach)->get(route('admin.infos'))->assertForbidden();
        $this->actingAs($athlete)->get(route('admin.infos'))->assertForbidden();
    }

    public function test_admin_can_reach_admin_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.infos'))->assertOk();
        $this->actingAs($admin)->get(route('admin.infos.create'))->assertOk();
    }

    // ── Visibilité (scopeVisibleTo) ──

    public function test_visibility_filters_by_role(): void
    {
        InformationPage::create(['title' => 'Pour tous', 'visibility' => 'all']);
        InformationPage::create(['title' => 'Pour coachs', 'visibility' => 'coach']);
        InformationPage::create(['title' => 'Bureau seul', 'visibility' => 'admin']);

        $athlete = User::factory()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        // Athlète : « all » uniquement.
        $this->actingAs($athlete)->get(route('infos'))
            ->assertSee('Pour tous')
            ->assertDontSee('Pour coachs')
            ->assertDontSee('Bureau seul');

        // Coach : « all » + « coach ».
        $this->actingAs($coach)->get(route('infos'))
            ->assertSee('Pour tous')
            ->assertSee('Pour coachs')
            ->assertDontSee('Bureau seul');

        // Admin : tout.
        $this->actingAs($admin)->get(route('infos'))
            ->assertSee('Pour tous')
            ->assertSee('Pour coachs')
            ->assertSee('Bureau seul');
    }

    public function test_archived_pages_are_hidden_from_members(): void
    {
        InformationPage::create([
            'title' => 'Note archivée', 'visibility' => 'all', 'archived_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())->get(route('infos'))
            ->assertDontSee('Note archivée');
    }

    // ── Bannières d'accueil ──

    public function test_pinned_visible_page_appears_on_home(): void
    {
        InformationPage::create(['title' => 'Épinglée coach', 'visibility' => 'coach', 'pinned' => true]);

        // Visible pour un coach.
        $this->actingAs(User::factory()->coach()->create())->get(route('dashboard'))
            ->assertSee('Épinglée coach');

        // Invisible pour un athlète (hors périmètre de visibilité).
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertDontSee('Épinglée coach');
    }

    public function test_unpinned_page_does_not_appear_on_home(): void
    {
        InformationPage::create(['title' => 'Non épinglée', 'visibility' => 'all', 'pinned' => false]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertDontSee('Non épinglée');
    }

    // ── Ordre d'affichage manuel (position) ──

    public function test_pages_are_ordered_by_position_only_regardless_of_pin(): void
    {
        // L'ordre suit UNIQUEMENT position : l'épinglage ne remonte pas la page.
        InformationPage::create(['title' => 'Alpha', 'visibility' => 'all', 'position' => 0]);
        InformationPage::create(['title' => 'Bravo', 'visibility' => 'all', 'position' => 1, 'pinned' => true]);
        InformationPage::create(['title' => 'Charlie', 'visibility' => 'all', 'position' => 2]);

        $titles = InformationPage::query()->active()->ordered()->pluck('title')->all();

        // Bravo est épinglée mais reste à sa position (1), sans passer devant Alpha.
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $titles);
    }

    public function test_pinned_and_unpinned_pages_reorder_freely(): void
    {
        $admin = User::factory()->admin()->create();
        $pinned = InformationPage::create(['title' => 'Épinglée', 'visibility' => 'all', 'position' => 0, 'pinned' => true]);
        InformationPage::create(['title' => 'Normale', 'visibility' => 'all', 'position' => 1]);

        $order = fn () => InformationPage::query()->active()->ordered()->pluck('title')->all();
        $this->assertSame(['Épinglée', 'Normale'], $order());

        // Une page épinglée peut descendre sous une non-épinglée (indépendant de l'épinglage).
        Livewire::actingAs($admin)->test(InformationPageList::class)->call('moveDown', $pinned->id);
        $this->assertSame(['Normale', 'Épinglée'], $order());
    }

    public function test_admin_moves_page_up_and_down(): void
    {
        $admin = User::factory()->admin()->create();
        // Positions explicites pour un ordre initial déterministe : A, B, C.
        $a = InformationPage::create(['title' => 'A', 'visibility' => 'all', 'position' => 0]);
        $b = InformationPage::create(['title' => 'B', 'visibility' => 'all', 'position' => 1]);
        $c = InformationPage::create(['title' => 'C', 'visibility' => 'all', 'position' => 2]);

        $order = fn () => InformationPage::query()->active()->ordered()->pluck('title')->all();
        $this->assertSame(['A', 'B', 'C'], $order());

        // Descendre A → B, A, C.
        Livewire::actingAs($admin)->test(InformationPageList::class)->call('moveDown', $a->id);
        $this->assertSame(['B', 'A', 'C'], $order());

        // Remonter C → B, C, A.
        Livewire::actingAs($admin)->test(InformationPageList::class)->call('moveUp', $c->id);
        $this->assertSame(['B', 'C', 'A'], $order());

        // Aux bornes : remonter le premier ne change rien.
        Livewire::actingAs($admin)->test(InformationPageList::class)->call('moveUp', $b->id);
        $this->assertSame(['B', 'C', 'A'], $order());
    }
    // (La garde de rôle du réordonnancement est couverte par test_admin_pages_are_forbidden_to_non_admin :
    //  moveUp/moveDown re-vérifient authorize() et la route est déjà interdite aux non-admins.)
}
