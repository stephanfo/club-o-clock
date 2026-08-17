<?php

namespace Tests\Feature;

use App\Models\GpxRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Indicateur de relief (D+/km) — PRD §4.20.
 *
 * Les seuils sont RELATIFS au terrain du club (3,9 à 9,1 m/km sur le corpus réel) : un barème
 * absolu de cyclisme classerait tout en « facile ». Ce qui est testé ici n'est donc pas la valeur
 * des seuils, mais l'invariant qui compte : le libellé affiché et le filtre classent la MÊME chose.
 */
class GpxRouteGradeTest extends TestCase
{
    use RefreshDatabase;

    private function route(?int $dplus, ?float $km): GpxRoute
    {
        return GpxRoute::factory()->create(['dplus_m' => $dplus, 'distance_km' => $km]);
    }

    public function test_grade_index_is_dplus_per_kilometre(): void
    {
        $this->assertSame(7.3, $this->route(365, 50.0)->gradeIndex());
    }

    public function test_grade_index_is_null_without_usable_metrics(): void
    {
        $this->assertNull($this->route(null, 50.0)->gradeIndex());
        $this->assertNull($this->route(300, 0)->gradeIndex());
        $this->assertNull($this->route(null, 50.0)->gradeLabel());
    }

    public function test_labels_follow_the_club_calibrated_thresholds(): void
    {
        $this->assertSame('Roulant', $this->route(215, 54.7)->gradeLabel());   // 3,9
        $this->assertSame('Vallonné', $this->route(348, 55.1)->gradeLabel());  // 6,3
        $this->assertSame('Exigeant', $this->route(771, 84.5)->gradeLabel());  // 9,1
    }

    public function test_scope_partitions_the_library_without_gap_or_overlap(): void
    {
        foreach ([[215, 54.7], [348, 55.1], [771, 84.5], [536, 73.6], [422, 53.8]] as [$d, $km]) {
            $this->route($d, $km);
        }

        $counts = collect(['rolling', 'hilly', 'tough'])
            ->map(fn ($g) => GpxRoute::grade($g)->count());

        $this->assertSame(GpxRoute::count(), $counts->sum(), 'Les 3 filtres doivent couvrir toute la bibliothèque.');
    }

    /**
     * L'invariant central : ce qui est AFFICHÉ fait foi. Le filtre SQL arrondit comme le libellé PHP,
     * sinon une trace à 7,2503 s'affiche « Exigeant · 7,3 » tout en sortant du filtre « Exigeant »
     * (4 des 18 traces du corpus réel étaient dans ce cas avant correction).
     */
    public function test_label_and_filter_agree_on_rounding_boundaries(): void
    {
        $this->route(617, 85.1);  // 7,2503 → arrondi 7,3
        $this->route(356, 49.1);  // 7,2505 → arrondi 7,3
        $this->route(536, 73.6);  // 7,2826 → arrondi 7,3

        $map = ['rolling' => 'Roulant', 'hilly' => 'Vallonné', 'tough' => 'Exigeant'];

        foreach ($map as $scope => $label) {
            foreach (GpxRoute::grade($scope)->get() as $route) {
                $this->assertSame($label, $route->gradeLabel(), "{$route->name} ({$route->gradeIndex()} m/km) est mal classé.");
            }
        }

        $this->assertSame(3, GpxRoute::grade('tough')->count());
    }

    public function test_unknown_grade_does_not_filter(): void
    {
        $this->route(300, 50.0);

        $this->assertSame(GpxRoute::count(), GpxRoute::grade(null)->count());
        $this->assertSame(GpxRoute::count(), GpxRoute::grade('n_importe_quoi')->count());
    }

    public function test_routes_without_metrics_are_excluded_from_every_grade_filter(): void
    {
        $this->route(null, 50.0);

        foreach (['rolling', 'hilly', 'tough'] as $grade) {
            $this->assertSame(0, GpxRoute::grade($grade)->count());
        }
    }
}
