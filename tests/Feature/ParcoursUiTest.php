<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Parcours OpenRunner sur le formulaire et la fiche (PRD §4.13.1).
class ParcoursUiTest extends TestCase
{
    use RefreshDatabase;

    private function discipline(): Discipline
    {
        return Discipline::create(['label' => 'Vélo', 'sort_order' => 0]);
    }

    public function test_valid_embed_url_is_saved(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Sortie vélo Loire')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('route_openrunner_embed_url', 'https://www.openrunner.com/embed.html?code=AbC123')
            ->set('route_openrunner_public_url', 'https://www.openrunner.com/r/18234210')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', [
            'title' => 'Sortie vélo Loire',
            'route_openrunner_embed_url' => 'https://www.openrunner.com/embed.html?code=AbC123',
        ]);
    }

    public function test_invalid_embed_url_is_rejected(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Mauvais lien')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('route_openrunner_embed_url', 'https://evil.com/embed.html?code=x')
            ->call('save')
            ->assertHasErrors('route_openrunner_embed_url');

        $this->assertDatabaseMissing('sessions', ['title' => 'Mauvais lien']);
    }

    public function test_fiche_renders_iframe_and_link(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Sortie', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'capacity' => 10,
            'created_by' => $coach->id,
            'route_openrunner_embed_url' => 'https://www.openrunner.com/embed.html?code=AbC123',
            'route_openrunner_public_url' => 'https://www.openrunner.com/r/18234210',
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertSee('https://www.openrunner.com/embed.html?code=AbC123', false)
            ->assertSee('Carte du parcours OpenRunner')
            ->assertSee('https://www.openrunner.com/r/18234210', false);
    }

    /**
     * Arbitrage du 2026-08-15 (§4.13.3 laissait le placement « à arbitrer aux maquettes ») :
     * quand les deux sources coexistent, c'est le TRACÉ GPX qui s'ouvre par défaut, pas la
     * carte OpenRunner. Verrouillé par un test parce que rien dans le code ne le rend
     * évident : c'est une valeur initiale d'Alpine, qu'une retouche du bloc inverserait sans
     * bruit.
     */
    public function test_gpx_is_the_default_tab_when_both_sources_coexist(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();
        $route = GpxRoute::factory()->create(['discipline_id' => $disc->id]);
        $s = Session::create([
            'kind' => 'training', 'title' => 'Sortie', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'capacity' => 10,
            'created_by' => $coach->id,
            'route_id' => $route->id,
            'route_openrunner_embed_url' => 'https://www.openrunner.com/embed.html?code=AbC123',
        ]);

        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])->html();

        $this->assertStringContainsString("tab: 'gpx'", $html, 'Le tracé GPX doit être l\'onglet ouvert par défaut.');

        // L'ordre du segment doit suivre le défaut, sinon l'onglet actif n'est pas le premier.
        $this->assertLessThan(
            mb_strpos($html, 'Carte OpenRunner'),
            mb_strpos($html, 'Tracé GPX'),
            'Le segment doit présenter « Tracé GPX » avant « Carte OpenRunner ».'
        );
    }

    /** Seul OpenRunner renseigné : rien à arbitrer, la carte reste l'onglet ouvert. */
    public function test_openrunner_stays_default_when_it_is_the_only_source(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = $this->discipline();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Sortie', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 90, 'capacity' => 10,
            'created_by' => $coach->id,
            'route_openrunner_embed_url' => 'https://www.openrunner.com/embed.html?code=AbC123',
        ]);

        $html = Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])->html();

        $this->assertStringContainsString("tab: 'carte'", $html);
        // Pas de coexistence => pas de chargement différé : l'iframe doit être là d'emblée.
        $this->assertStringContainsString('armed: true', $html);
    }
}
