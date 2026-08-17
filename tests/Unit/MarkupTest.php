<?php

namespace Tests\Unit;

use App\Support\Markup;
use PHPUnit\Framework\TestCase;

// Sanitisation du texte enrichi WYSIWYG (PRD §4.12.1) — PHP faisant foi.
class MarkupTest extends TestCase
{
    private function render(string $md): string
    {
        return (string) Markup::render($md);
    }

    public function test_allows_the_fixed_perimeter(): void
    {
        $html = $this->render("**gras** *ital* ~~barré~~\n\n## Titre 2\n\n### Titre 3\n\n- a\n- b\n\n1. x\n2. y\n\n> citation");

        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('<em>ital</em>', $html);
        $this->assertStringContainsString('<del>barré</del>', $html);
        $this->assertStringContainsString('<h2>Titre 2</h2>', $html);
        $this->assertStringContainsString('<h3>Titre 3</h3>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
    }

    public function test_external_link_gets_target_and_rel(): void
    {
        $html = $this->render('[club](https://club.example)');

        $this->assertStringContainsString('href="https://club.example"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_neutralizes_raw_html(): void
    {
        $html = $this->render("<script>alert(1)</script>\n\nhello");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringContainsString('hello', $html);
    }

    public function test_strips_javascript_links_keeping_text(): void
    {
        $html = $this->render('[x](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('x', $html);
    }

    public function test_demotes_h1_to_h2(): void
    {
        $this->assertStringContainsString('<h2>Grand titre</h2>', $this->render('# Grand titre'));
    }

    public function test_drops_inline_image(): void
    {
        $html = $this->render('![alt](https://x/y.png)');

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_clean_returns_null_for_empty(): void
    {
        $this->assertNull(Markup::clean(''));
        $this->assertNull(Markup::clean("   \n  "));
        $this->assertNull(Markup::clean('<p></p>'));
    }

    public function test_clean_canonicalizes_to_allowed_markdown(): void
    {
        $md = Markup::clean('**gras** et [lien](https://x.fr)');

        $this->assertNotNull($md);
        // Re-rendu du markdown nettoyé : toujours sûr, sans script.
        $html = $this->render($md);
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('href="https://x.fr"', $html);
    }

    public function test_clean_strips_disallowed_html_payload(): void
    {
        $md = Markup::clean('<img src=x onerror=alert(1)> **safe**');

        $this->assertNotNull($md);
        $this->assertStringNotContainsString('onerror', (string) Markup::render($md));
        $this->assertStringNotContainsString('<img', (string) Markup::render($md));
    }

    // Régression : html-to-markdown ne convertit pas <del> par défaut → le barré était perdu au
    // stockage (texte conservé, balisage disparu). StrikethroughConverter rétablit le `~~…~~`.
    public function test_clean_preserves_strikethrough(): void
    {
        $md = Markup::clean('Texte ~~barré~~ normal.');

        $this->assertStringContainsString('~~barré~~', (string) $md);
        $this->assertStringContainsString('<del>barré</del>', (string) Markup::render($md));
    }
}
