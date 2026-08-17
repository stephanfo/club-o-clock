<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le pas de graduation kilométrique est écrit DEUX FOIS : en PHP dans le composant du profil
 * altimétrique (`$stepKm`) et en JS dans `kmStepFor()` pour les bornes de la carte.
 *
 * Les deux doivent rester identiques, sinon un parcours affiche « 5, 10, 15 » sous son profil et des
 * pastilles « 10, 20 » sur sa carte — lire les deux côte à côte devient un exercice de conversion.
 * Le duplicata est assumé (le profil est rendu serveur, la carte parse le GPX côté client, cadrage
 * §7.6 : le serveur ne parse jamais de GPX), mais il doit être surveillé.
 *
 * Ce test compare les SEUILS extraits des deux fichiers plutôt que d'exécuter le JS : il n'y a pas
 * de runner JS dans le projet, et en installer un pour six nombres serait disproportionné.
 */
class KmStepSyncTest extends TestCase
{
    /** @return list<array{float, float}> paires [seuil, pas] */
    private function phpThresholds(): array
    {
        $blade = file_get_contents(__DIR__.'/../../resources/views/components/alt-profile.blade.php');
        // `$lengthKm <= 6 => 1,` … et le `default => 50,` final.
        preg_match_all('/\$lengthKm <= ([\d.]+) => ([\d.]+)/', (string) $blade, $m);
        $out = [];
        foreach ($m[1] as $i => $threshold) {
            $out[] = [(float) $threshold, (float) $m[2][$i]];
        }
        preg_match('/default => ([\d.]+)/', (string) $blade, $d);
        $out[] = [INF, (float) $d[1]];

        return $out;
    }

    /** @return list<array{float, float}> */
    private function jsThresholds(): array
    {
        $js = file_get_contents(__DIR__.'/../../resources/js/gpx.js');
        preg_match('/export function kmStepFor\(totalKm\) \{(.+?)\n\}/s', (string) $js, $fn);
        $this->assertNotEmpty($fn, 'kmStepFor() introuvable dans gpx.js — la fonction a-t-elle été renommée ?');

        preg_match_all('/totalKm <= ([\d.]+)\) return ([\d.]+)/', $fn[1], $m);
        $out = [];
        foreach ($m[1] as $i => $threshold) {
            $out[] = [(float) $threshold, (float) $m[2][$i]];
        }
        // Le `return` de repli est le DERNIER de la fonction, seul sur sa ligne et sans condition —
        // sans l'ancrer ainsi, la regex attrape le premier `return` conditionnel rencontré.
        preg_match_all('/^\s*return ([\d.]+);/m', $fn[1], $d);
        $out[] = [INF, (float) end($d[1])];

        return $out;
    }

    public function test_the_kilometre_step_is_identical_in_php_and_js(): void
    {
        $php = $this->phpThresholds();

        $this->assertGreaterThanOrEqual(3, count($php), 'Seuils PHP non extraits : le format de $stepKm a changé.');
        $this->assertSame($php, $this->jsThresholds(),
            'Le pas des graduations du profil (alt-profile.blade.php) et celui des bornes de la carte '.
            '(kmStepFor dans gpx.js) ont divergé : les kilomètres affichés ne coïncideraient plus.');
    }

    /**
     * Tables identiques ne suffit pas : encore faut-il que les deux fonctions reçoivent la MÊME
     * grandeur. Bug réel (revue 2026-08-02) — le profil se basait sur son dernier échantillon, qui
     * tombe un pas avant la fin (~0,8 %). Sur un 30,06 km, ce dernier point vaut 29,81 : le profil
     * repassait sous le seuil des 30 km et graduait tous les 5 km là où la carte graduait tous les
     * 10. Le composant doit donc raisonner sur la longueur RÉELLE, transmise par `distance-km`.
     */
    public function test_the_profile_uses_the_real_length_not_its_last_sample(): void
    {
        $blade = (string) file_get_contents(__DIR__.'/../../resources/views/components/alt-profile.blade.php');

        $this->assertMatchesRegularExpression('/\$lengthKm\s*=\s*\$distanceKm !== null/', $blade,
            'Le pas doit se calculer sur la longueur réelle (prop distance-km), pas sur $maxX.');

        // Le seuil est franchi juste au-dessus : c'est là que la divergence se manifestait.
        foreach ([[30.06, 29.81, 10.0], [12.065, 11.964, 5.0], [60.101, 59.6, 20.0]] as [$real, $lastSample, $expected]) {
            $this->assertSame($expected, $this->stepFor($real, $this->phpThresholds()),
                "Longueur réelle {$real} km : pas attendu {$expected}.");
            // Preuve que le bug existait : le dernier échantillon donnait un autre pas.
            $this->assertNotSame($expected, $this->stepFor($lastSample, $this->phpThresholds()),
                "Le cas {$real} km ne démontre plus rien : dernier échantillon et longueur réelle ".
                'donnent désormais le même pas — choisir une autre valeur de test.');
        }
    }

    /** @param list<array{float, float}> $thresholds */
    private function stepFor(float $km, array $thresholds): float
    {
        foreach ($thresholds as [$max, $step]) {
            if ($km <= $max) {
                return $step;
            }
        }

        return end($thresholds)[1];
    }
}
