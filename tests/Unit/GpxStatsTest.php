<?php

namespace Tests\Unit;

use App\Support\GpxStats;
use PHPUnit\Framework\TestCase;

// Garde serveur des données GPX (PRD §4.20, cadrage §7.6). Les valeurs testées ici arrivent du
// navigateur : elles sont, par construction, non fiables.
class GpxStatsTest extends TestCase
{
    // ── Métriques : régime CLAMP (une valeur aberrante bornée est sans conséquence) ──

    public function test_metrics_are_clamped_not_rejected(): void
    {
        $out = GpxStats::sanitize([
            'name' => 'sortie.gpx',
            'distanceKm' => 51.5,
            'pointCount' => 999999999999,
            'altMin' => -99999,
        ]);

        $this->assertSame(51.5, $out['distanceKm']);
        $this->assertEqualsWithDelta(10000000, $out['pointCount'], 0.001);
        $this->assertEqualsWithDelta(-1000, $out['altMin'], 0.001);
    }

    public function test_sanitize_returns_null_on_empty_input(): void
    {
        $this->assertNull(GpxStats::sanitize(null));
        $this->assertNull(GpxStats::sanitize([]));
    }

    // ── Géo : régime REJET EN BLOC (clamper une coordonnée déplacerait le parcours) ──

    public function test_valid_geo_block_is_kept(): void
    {
        $geo = GpxStats::sanitizeGeo($this->geo());

        $this->assertNotNull($geo);
        $this->assertEqualsWithDelta(47.55, $geo['start_lat'], 0.0001);
        $this->assertSame('NE', $geo['sector']);
    }

    public function test_latitude_out_of_range_nulls_the_whole_geo_block(): void
    {
        $this->assertNull(GpxStats::sanitizeGeo($this->geo(['start' => ['lat' => 91, 'lon' => 1.335]])));
    }

    public function test_inverted_bbox_nulls_the_whole_geo_block(): void
    {
        $this->assertNull(GpxStats::sanitizeGeo($this->geo([
            'bbox' => ['minLat' => 47.62, 'minLon' => 1.30, 'maxLat' => 47.55, 'maxLon' => 1.40],
        ])));
    }

    public function test_start_outside_bbox_nulls_the_whole_geo_block(): void
    {
        // Départ en Bretagne, emprise en Loir-et-Cher : les deux ne décrivent pas la même trace.
        $this->assertNull(GpxStats::sanitizeGeo($this->geo(['start' => ['lat' => 48.5, 'lon' => -2.0]])));
    }

    public function test_is_loop_is_recomputed_server_side(): void
    {
        // Le client prétend que ce n'est pas une boucle alors que départ ≈ arrivée : on recalcule.
        $geo = GpxStats::sanitizeGeo($this->geo(['isLoop' => false]));
        $this->assertTrue($geo['is_loop']);

        // Arrivée au coin opposé de l'emprise : pas une boucle, quoi qu'en dise le client.
        $geo = GpxStats::sanitizeGeo($this->geo([
            'end' => ['lat' => 47.6200, 'lon' => 1.4000],
            'isLoop' => true,
        ]));
        $this->assertFalse($geo['is_loop']);
    }

    public function test_sector_is_recomputed_from_bearing_not_trusted(): void
    {
        // Le client annonce « S » ; la géométrie dit nord-est. Le serveur fait autorité.
        $geo = GpxStats::sanitizeGeo($this->geo(['sector' => 'S', 'bearing' => 180]));

        $this->assertSame('NE', $geo['sector']);
        $this->assertGreaterThan(0, $geo['bearing_deg']);
        $this->assertLessThan(90, $geo['bearing_deg']);
    }

    /**
     * Le cap vise le CENTROÏDE de la bbox, pas le point le plus éloigné : c'est ce qui rend le
     * secteur stable sur une boucle, quel que soit l'endroit d'où on la démarre.
     * Protège la décision de cadrage du 2026-08-01 contre une régression.
     */
    public function test_sector_is_stable_across_different_start_points_on_same_bbox(): void
    {
        $bbox = ['minLat' => 47.50, 'minLon' => 1.20, 'maxLat' => 47.70, 'maxLon' => 1.60];

        $a = GpxStats::sanitizeGeo([
            'start' => ['lat' => 47.52, 'lon' => 1.22],
            'end' => ['lat' => 47.52, 'lon' => 1.22],
            'bbox' => $bbox,
        ]);
        $b = GpxStats::sanitizeGeo([
            'start' => ['lat' => 47.53, 'lon' => 1.24],
            'end' => ['lat' => 47.53, 'lon' => 1.24],
            'bbox' => $bbox,
        ]);

        $this->assertSame($a['sector'], $b['sector']);
    }

    public function test_elongation_distinguishes_round_from_stretched_circuits(): void
    {
        // Emprise quasi carrée → circuit arrondi.
        $round = GpxStats::sanitizeGeo($this->geo([
            'bbox' => ['minLat' => 47.5000, 'minLon' => 1.3000, 'maxLat' => 47.6800, 'maxLon' => 1.5670],
        ]));
        $this->assertLessThan(1.45, (float) $round['elongation']);

        // Emprise nettement plus haute que large → circuit étiré.
        $stretched = GpxStats::sanitizeGeo($this->geo([
            'bbox' => ['minLat' => 47.5000, 'minLon' => 1.3000, 'maxLat' => 47.8000, 'maxLon' => 1.3600],
        ]));
        $this->assertGreaterThan(1.45, (float) $stretched['elongation']);
    }

    public function test_elongation_is_null_on_degenerate_bbox(): void
    {
        // Trace rectiligne : l'emprise n'a pas d'épaisseur, aucune forme n'est exploitable.
        $geo = GpxStats::sanitizeGeo($this->geo([
            'start' => ['lat' => 47.5500, 'lon' => 1.3000],
            'end' => ['lat' => 47.5500, 'lon' => 1.3000],
            'bbox' => ['minLat' => 47.5500, 'minLon' => 1.3000, 'maxLat' => 47.5500, 'maxLon' => 1.4000],
        ]));

        $this->assertNull($geo['elongation']);
    }

    public function test_sector_boundary_rounding(): void
    {
        // 8 secteurs de 45°, centrés : la frontière N/NE tombe à 22,5°.
        $this->assertSame('N', GpxStats::sectorFromBearing(22));
        $this->assertSame('NE', GpxStats::sectorFromBearing(23));
        $this->assertSame('N', GpxStats::sectorFromBearing(0));
        $this->assertSame('N', GpxStats::sectorFromBearing(359));
        // Français : O, jamais W.
        $this->assertSame('O', GpxStats::sectorFromBearing(270));
        $this->assertNull(GpxStats::sectorFromBearing(null));
    }

    // ── Polyline et profil : troncature dure, garde-fou anti-payload hostile ──

    public function test_polyline_is_truncated_to_hard_cap(): void
    {
        $huge = array_fill(0, 10000, [47.5, 1.3]);

        $this->assertCount(GpxStats::MAX_POLYLINE_POINTS, GpxStats::sanitizePolyline($huge));
    }

    public function test_polyline_drops_invalid_pairs_and_rounds(): void
    {
        $out = GpxStats::sanitizePolyline([
            [47.5863456789, 1.3351234567],
            [999, 1.3],          // latitude hors bornes → écartée
            ['x', 'y'],          // non numérique → écartée
            [47.6, 1.4],
        ]);

        $this->assertCount(2, $out);
        $this->assertSame([47.58635, 1.33512], $out[0]);
    }

    public function test_polyline_needs_at_least_two_points(): void
    {
        $this->assertNull(GpxStats::sanitizePolyline([[47.5, 1.3]]));
        $this->assertNull(GpxStats::sanitizePolyline([]));
        $this->assertNull(GpxStats::sanitizePolyline('pas un tableau'));
    }

    public function test_elevation_profile_is_truncated_and_bounded(): void
    {
        $out = GpxStats::sanitizeElevationProfile([
            [0, 62],
            [10.5, 99999],       // altitude aberrante → clampée
            [-5, 120],           // distance négative → clampée à 0
        ]);

        $this->assertEqualsWithDelta(10000, $out[1][1], 0.001);
        $this->assertEqualsWithDelta(0, $out[2][0], 0.001);

        $this->assertCount(
            GpxStats::MAX_PROFILE_POINTS,
            GpxStats::sanitizeElevationProfile(array_fill(0, 5000, [1, 100]))
        );
    }

    /**
     * Bloc géo valide de référence : départ au coin sud-ouest de l'emprise, donc centroïde
     * franchement au nord-est — le secteur attendu vaut « NE ».
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function geo(array $overrides = []): array
    {
        return [
            'start' => ['lat' => 47.5500, 'lon' => 1.3000],
            'end' => ['lat' => 47.5501, 'lon' => 1.3001],
            'isLoop' => true,
            'bbox' => ['minLat' => 47.5500, 'minLon' => 1.3000, 'maxLat' => 47.6200, 'maxLon' => 1.4000],
            'bearing' => 45,
            'sector' => 'NE',
            ...$overrides,
        ];
    }
}
