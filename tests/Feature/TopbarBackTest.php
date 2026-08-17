<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour contextuel du chevron de topbar (correctif navigation 2026-08-02).
 *
 * Le chevron pointait vers une URL EN DUR : revenir d'une fiche parcours ramenait toujours à la
 * bibliothèque — filtres perdus — même quand on venait d'une séance. Il tente désormais un vrai
 * retour arrière (`window.clubBack()`), l'URL ne servant que de repli.
 *
 * Ces tests portent sur le COMPOSANT, donc valent pour les cinq écrans qui l'utilisent.
 */
class TopbarBackTest extends TestCase
{
    use RefreshDatabase;

    private function render(string $blade): string
    {
        return (string) $this->blade($blade);
    }

    public function test_the_back_chevron_calls_club_back_before_following_its_href(): void
    {
        $html = $this->render('<x-topbar title="T" back="/parcours" back-label="Retour" />');

        $this->assertStringContainsString('window.clubBack', $html);
        $this->assertStringContainsString('/parcours', $html);
        $this->assertStringContainsString('Retour', $html);
    }

    /**
     * LE point qui casse le correctif s'il régresse : `wire:navigate` navigue dès `mousedown`
     * (whenThisLinkIsPressed), donc AVANT tout `onclick`. Le repli partirait systématiquement et le
     * retour historique ne s'exécuterait jamais. Même piège que wire:click + wire:navigate empilés.
     */
    public function test_the_back_chevron_never_carries_wire_navigate(): void
    {
        $html = $this->render('<x-topbar title="T" back="/parcours" />');

        $this->assertStringNotContainsString('wire:navigate', $html);
    }

    /** Sans `back`, pas de chevron du tout — et donc pas d'appel à clubBack. */
    public function test_no_chevron_without_a_fallback_url(): void
    {
        $html = $this->render('<x-topbar title="T" />');

        $this->assertStringNotContainsString('window.clubBack', $html);
    }
}
