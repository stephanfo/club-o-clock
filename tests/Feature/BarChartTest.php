<?php

namespace Tests\Feature;

use Tests\TestCase;

// Composant x-bar-chart (PRD §4.16.1, #160) : SVG serveur, axe gradué à valeurs rondes, valeurs
// écrites tant que les barres peuvent les porter.
class BarChartTest extends TestCase
{
    private function render(array $points): string
    {
        return (string) $this->blade('<x-bar-chart :points="$points" legend="Participations" line-legend="Athlètes" />', ['points' => $points]);
    }

    /** Libellés de graduation Y, dans l'ordre du balisage. */
    private function ticks(string $html): array
    {
        preg_match_all('/class="chart-tick"[^>]*>(\d+)</', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_y_axis_uses_round_ticks_above_the_maximum(): void
    {
        $this->assertSame([0, 50, 100, 150, 200, 250], $this->ticks($this->render([['value' => 212], ['value' => 49]])));
        $this->assertSame([0, 10, 20, 30, 40], $this->ticks($this->render([['value' => 38]])));
        $this->assertSame([0, 2, 4, 6, 8], $this->ticks($this->render([['value' => 7]])));
    }

    public function test_the_line_series_counts_in_the_scale(): void
    {
        // La ligne dépasse les barres : l'axe doit la contenir.
        $this->assertSame([0, 10, 20, 30, 40], $this->ticks($this->render([['value' => 5, 'line' => 33]])));
    }

    public function test_all_zero_series_still_draws_an_axis(): void
    {
        $this->assertSame([0, 1], $this->ticks($this->render([['value' => 0], ['value' => 0]])));
    }

    public function test_values_are_written_while_bars_are_wide_enough(): void
    {
        $few = $this->render(array_fill(0, 16, ['value' => 12]));
        $this->assertSame(16, substr_count($few, 'class="chart-val"'));

        $many = $this->render(array_fill(0, 17, ['value' => 12]));
        $this->assertSame(0, substr_count($many, 'class="chart-val"'));
        $this->assertStringContainsString('class="chart-tick"', $many); // contrôle positif : l'axe reste
    }

    public function test_line_legend_only_when_a_line_exists(): void
    {
        $this->assertStringContainsString('Athlètes', $this->render([['value' => 3, 'line' => 2]]));
        $this->assertStringNotContainsString('Athlètes', $this->render([['value' => 3]]));
    }

    public function test_muted_bar_is_dimmed_and_empty_series_renders_nothing(): void
    {
        $this->assertStringContainsString('fill-opacity="0.4"', $this->render([['value' => 3], ['value' => 2, 'muted' => true]]));
        $this->assertStringNotContainsString('fill-opacity', $this->render([['value' => 3]]));
        $this->assertStringNotContainsString('chart', $this->render([]));
    }
}
