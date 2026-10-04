<?php

namespace Tests\Unit;

use App\Support\Allures\Calculateur as C;
use PHPUnit\Framework\TestCase;

// Formules des allures course (#114). Valeurs de test inventées : aucun coefficient de coach ici.
class AlluresCalculateurTest extends TestCase
{
    public function test_allure_et_temps_a_un_pourcentage_de_vma(): void
    {
        // 15 km/h à 100 % = 4:00 /km, 400 m en 1:36.
        $this->assertEqualsWithDelta(240.0, C::allure(15, 100), 0.001);
        $this->assertEqualsWithDelta(96.0, C::temps(400, 15, 100), 0.001);
        // À 80 % : 12 km/h = 5:00 /km.
        $this->assertSame('5:00', C::formatAllure(C::allure(15, 80)));
    }

    public function test_riegel_pourcentage_tenable_decroit_avec_la_duree(): void
    {
        $this->assertEqualsWithDelta(100.0, C::pctRiegel(C::DUREE_VMA), 0.001);
        $this->assertEqualsWithDelta(88.9, C::pctRiegel(47 * 60 + 45), 0.1);
        $this->assertEqualsWithDelta(81.8, C::pctRiegel(3.5 * 3600), 0.1);
    }

    public function test_riegel_estimation_et_projection_sont_inverses(): void
    {
        foreach ([[5000, 1320], [10000, 2865], [21097.5, 6540], [42195, 12600]] as [$d, $t]) {
            $vma = C::vmaRiegel($d, $t);
            $this->assertEqualsWithDelta($t, C::tempsRiegel($d, $vma), 0.5, "aller-retour {$d} m");
        }
    }

    public function test_riegel_coherent_avec_sa_propre_loi_entre_deux_distances(): void
    {
        // T2 = T1 · (D2/D1)^1,06, en passant par la VMA.
        $vma = C::vmaRiegel(10000, 2400);
        $this->assertEqualsWithDelta(2400 * (21097.5 / 10000) ** 1.06, C::tempsRiegel(21097.5, $vma), 1.0);
    }

    public function test_table_club_donne_des_fourchettes_et_gere_la_borne_ouverte(): void
    {
        // 10 km en 40:00 = 15 km/h ; tenu entre 80 et 90 % → VMA 16,7 à 18,75.
        [$low, $high] = C::vmaTable(10000, 2400, 80, 90);
        $this->assertEqualsWithDelta(15 / 0.9, $low, 0.001);
        $this->assertEqualsWithDelta(15 / 0.8, $high, 0.001);

        // Borne haute ouverte : traitée comme la borne basse.
        [$low, $high] = C::vmaTable(10000, 2400, 80, null);
        $this->assertEqualsWithDelta($low, $high, 0.001);

        // Projection : le % haut donne le temps rapide.
        [$fast, $slow] = C::tempsTable(10000, 15, 80, 90);
        $this->assertLessThan($slow, $fast);
        $this->assertEqualsWithDelta(10 * 3600 / 13.5, $fast, 0.01);
    }

    public function test_lecture_des_temps_saisis(): void
    {
        $this->assertSame(2865.0, C::parseTemps('47:45'));
        $this->assertSame(6540.0, C::parseTemps('1:49:00'));
        $this->assertSame(6540.0, C::parseTemps('1h49'));
        $this->assertSame(6540.0, C::parseTemps('1h49:00'));
        // Heures pleines : « 2h » sur un semi, pas deux minutes.
        $this->assertSame(7200.0, C::parseTemps('2h'));
        $this->assertSame(3600.0, C::parseTemps('1 h'));
        $this->assertSame(2700.0, C::parseTemps('45'));
        $this->assertNull(C::parseTemps('vite'));
        $this->assertNull(C::parseTemps(''));
        $this->assertNull(C::parseTemps('0:00'));
    }

    public function test_formats(): void
    {
        $this->assertSame('47:45', C::formatTemps(2865));
        $this->assertSame('1:49:00', C::formatTemps(6540));
        $this->assertSame('13,6', C::formatVma(13.64));
    }
}
