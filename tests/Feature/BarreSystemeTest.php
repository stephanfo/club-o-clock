<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Barre système (barre d'état iOS/Android, barre de titre d'une PWA de bureau) — #112.
//
// Depuis iOS 26.1, black-translucent ne laisse qu'un fondu clair avec l'heure en noir : la meta
// doit rester absente, et la barre se raccorde au haut du dégradé des en-têtes (--brand-700) —
// par la bande .barre-etat-ios sur iOS, par theme-color ailleurs. Une régression ne produirait
// aucune erreur, seulement une barre de la mauvaise couleur sur les téléphones.
class BarreSystemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_black_translucent_meta(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="apple-mobile-web-app-capable"', false)
            ->assertDontSee('apple-mobile-web-app-status-bar-style', false);
    }

    /** Palette par défaut : brand-700 de club-tokens.css. */
    public function test_theme_color_is_the_top_of_the_header_gradient(): void
    {
        $this->get(route('login'))->assertSee('<meta name="theme-color" content="#2f278d">', false);
        $this->assertSame('#2f278d', $this->get('/manifest.webmanifest')->json('theme_color'));
    }

    /** Couleur principale personnalisée (thèmes de saison) : la barre suit sa nuance 700. */
    public function test_theme_color_follows_the_club_palette(): void
    {
        ClubSettings::current()->update(['primary_color' => '#ea580c']);

        $this->get(route('login'))->assertSee('<meta name="theme-color" content="#a43e08">', false);
        $this->assertSame('#a43e08', $this->get('/manifest.webmanifest')->json('theme_color'));
    }

    public function test_app_layout_carries_the_ios_status_bar_strip(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="barre-etat-ios"', false);
    }
}
