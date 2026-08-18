<?php

namespace Tests\Unit;

use App\Models\ClubSettings;
use App\Support\ClubPalette;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Lisibilité du texte/des icônes posés sur un aplat de couleur de club.
//
// Ce bug est apparu DEUX fois : d'abord sur .btn-primary (libellé en var(--ink) en dur, illisible
// sur une primaire indigo), puis sur .disc-bike (icône vélo, même cause). À chaque fois parce
// qu'une couleur de premier plan était figée dans le CSS pour la palette d'origine.
//
// L'invariant verrouillé ici : pour CHAQUE couleur personnalisable, ClubPalette émet une couleur
// de premier plan dont le contraste WCAG atteint au moins 4.5:1 (seuil AA pour du texte normal).
class ClubPaletteContrastTest extends TestCase
{
    private const INK = '#0a0a0a';

    private const PAPER = '#ffffff';

    /** Chaque token personnalisable doit émettre SA couleur de premier plan. */
    public function test_emits_a_foreground_token_for_every_customisable_color(): void
    {
        $css = ClubPalette::overrideCss($this->settings('#4338CA', '#F59E0B', '#0891B2'));

        $this->assertStringContainsString('--fg-on-brand:', $css);
        $this->assertStringContainsString('--fg-on-accent:', $css);
        $this->assertStringContainsString('--fg-on-info:', $css);
        $this->assertStringContainsString('--fg-on-primary:', $css, 'Alias historique de --fg-on-brand, encore utilisé par le CSS.');
    }

    /**
     * Le cas signalé : palette indigo/ambre/cyan. L'indigo est sombre (il appelle du blanc),
     * l'ambre et le cyan sont clairs (ils appellent du noir) — trois réponses différentes.
     */
    public function test_reported_palette_gets_a_readable_foreground_on_each_color(): void
    {
        $css = ClubPalette::overrideCss($this->settings('#4338CA', '#F59E0B', '#0891B2'));

        $this->assertStringContainsString('--fg-on-brand: var(--paper);', $css, 'Indigo sombre → blanc.');
        $this->assertStringContainsString('--fg-on-accent: var(--ink);', $css, 'Ambre clair → noir.');
        $this->assertStringContainsString('--fg-on-info: var(--ink);', $css, 'Cyan clair → noir.');
    }

    /**
     * Le cœur de l'invariant : quelle que soit la couleur choisie, le premier plan retenu tient
     * le seuil AA. Les couleurs balayées couvrent les extrêmes (noir, blanc) et des teintes
     * moyennes, là où le choix se joue à peu de chose.
     */
    public function test_every_emitted_foreground_meets_wcag_aa(): void
    {
        $colors = [
            '#4338CA', '#F59E0B', '#0891B2',   // la palette neutre par défaut
            '#69bf2d', '#e4027d', '#3c69bb',   // une palette claire à l'opposé (vert/rose/bleu)
            '#000000', '#ffffff',              // extrêmes
            '#808080', '#7f7f7f',              // gris médians : le pire cas pour un choix binaire
            '#ff0000', '#00ff00', '#0000ff',
            '#1a1a2e', '#fefae0', '#264653',
        ];

        foreach ($colors as $hex) {
            $css = ClubPalette::overrideCss($this->settings($hex, $hex, $hex));

            preg_match('/--fg-on-brand: var\(--(ink|paper)\);/', $css, $m);
            $this->assertNotEmpty($m, "Aucune couleur de premier plan émise pour {$hex}.");

            $fg = $m[1] === 'ink' ? self::INK : self::PAPER;
            $ratio = $this->contrastRatio($hex, $fg);

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf('Contraste %.2f:1 seulement pour %s sur %s — sous le seuil AA de 4.5:1.', $ratio, $m[1], $hex),
            );
        }
    }

    /** Le premier plan retenu est toujours le MEILLEUR des deux, pas seulement un qui passe. */
    public function test_picks_the_better_of_ink_and_paper(): void
    {
        foreach (['#4338CA', '#F59E0B', '#0891B2', '#808080', '#264653'] as $hex) {
            $css = ClubPalette::overrideCss($this->settings($hex, $hex, $hex));
            preg_match('/--fg-on-brand: var\(--(ink|paper)\);/', $css, $m);

            $chosen = $m[1] === 'ink' ? self::INK : self::PAPER;
            $other = $m[1] === 'ink' ? self::PAPER : self::INK;

            $this->assertGreaterThanOrEqual(
                $this->contrastRatio($hex, $other),
                $this->contrastRatio($hex, $chosen),
                "Sur {$hex}, l'autre couleur de premier plan contrastait mieux.",
            );
        }
    }

    /** Sans personnalisation, rien n'est émis : les défauts de tokens.css s'appliquent. */
    public function test_emits_nothing_when_no_color_is_customised(): void
    {
        $this->assertSame('', ClubPalette::overrideCss($this->settings(null, null, null)));
    }

    /**
     * Construit un jeu de réglages ET purge le cache de palette.
     *
     * overrideCss() mémorise son résultat (le CSS ne dépend que de trois colonnes) : sans cette
     * purge, les boucles de ce test recevraient toutes la palette du premier appel, et les
     * assertions passeraient sans rien vérifier.
     */
    private function settings(?string $primary, ?string $accent, ?string $info): ClubSettings
    {
        Cache::forget(ClubPalette::CACHE_KEY);

        $settings = new ClubSettings;
        $settings->primary_color = $primary;
        $settings->accent_color = $accent;
        $settings->info_color = $info;

        return $settings;
    }

    /**
     * Ratios de référence, calculés INDÉPENDAMMENT de l'application (valeurs publiées par les
     * calculateurs de contraste WebAIM / WCAG, arrondies au centième).
     *
     * C'est l'oracle du test : recopier ici la formule de ClubPalette rendrait le test aveugle à
     * une erreur dans cette formule (mauvaise constante gamma, mauvais seuil de linéarisation) —
     * les deux côtés se tromperaient identiquement.
     *
     * @var array<string, array{0:string,1:float}>
     */
    private const KNOWN_RATIOS = [
        'indigo sur blanc' => ['#4338CA', self::PAPER, 7.90],
        'ambre sur blanc' => ['#F59E0B', self::PAPER, 2.15],
        'cyan sur blanc' => ['#0891B2', self::PAPER, 3.68],
        'noir pur sur blanc' => ['#000000', self::PAPER, 21.0],
        'blanc sur blanc' => [self::PAPER, self::PAPER, 1.0],
        'gris médian sur blanc' => ['#808080', self::PAPER, 3.95],
        'rouge sur blanc' => ['#ff0000', self::PAPER, 4.00],
        'vert vif sur noir' => ['#00ff00', '#000000', 15.30],
    ];

    /**
     * Verrouille la formule de contraste elle-même contre des valeurs de référence externes.
     *
     * Sans ce test, tous les autres restent verts même si la luminance est calculée de travers :
     * ils comparent le choix de ClubPalette à un ratio recalculé par la même formule.
     */
    public function test_contrast_formula_matches_published_reference_values(): void
    {
        foreach (self::KNOWN_RATIOS as $label => [$a, $b, $expected]) {
            $this->assertEqualsWithDelta(
                $expected,
                $this->contrastRatio($a, $b),
                0.02,
                "Ratio de contraste erroné pour {$label} — la formule WCAG a dérivé.",
            );
        }
    }

    /**
     * Ratio de contraste WCAG 2.x entre deux couleurs.
     *
     * Volontairement écrit ici plutôt qu'appelé sur ClubPalette (dont les méthodes sont privées) :
     * un test doit porter son propre oracle. Sa justesse est verrouillée par
     * test_contrast_formula_matches_published_reference_values() ci-dessus, qui le confronte à des
     * valeurs calculées hors de ce dépôt.
     */
    private function contrastRatio(string $a, string $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) $r) + 0.7152 * $channel((int) $g) + 0.0722 * $channel((int) $b);
    }
}
