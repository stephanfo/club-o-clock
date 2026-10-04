<?php

namespace App\Support\Allures;

/**
 * Formules des allures course (#114). Fonctions pures : VMA en km/h, distances en mètres,
 * temps en secondes, pourcentages en % (85 = 85 %).
 *
 * Deux modèles pour relier une course à la VMA :
 *  - **Riegel** (par défaut) : `T2 = T1 · (D2/D1)^1,06`, avec l'hypothèse que la VMA se tient
 *    6 min. Le % de VMA tenable sur une durée T vaut alors `(T / 360)^(−k)`, `k = 0,06 / 1,06`.
 *    Pas de notion de niveau, résultat ponctuel.
 *  - **Table club** : % de VMA tenable par niveau et par distance, saisi par l'admin. Résultat en
 *    fourchette (bornes de la table). Remplace Riegel dès qu'elle est remplie.
 */
final class Calculateur
{
    public const RIEGEL_EXPOSANT = 1.06;

    /** Durée (s) pendant laquelle la VMA est supposée tenable. */
    public const DUREE_VMA = 360.0;

    private static function k(): float
    {
        return (self::RIEGEL_EXPOSANT - 1) / self::RIEGEL_EXPOSANT;
    }

    /** Allure (s/km) à un % de VMA. */
    public static function allure(float $vma, float $pct): float
    {
        return 3600.0 / ($vma * $pct / 100.0);
    }

    /** Temps (s) pour couvrir une distance à un % de VMA. */
    public static function temps(float $distanceM, float $vma, float $pct): float
    {
        return $distanceM / 1000.0 * self::allure($vma, $pct);
    }

    /** % de VMA tenable sur une durée, selon Riegel. */
    public static function pctRiegel(float $secondes): float
    {
        return 100.0 * ($secondes / self::DUREE_VMA) ** (-self::k());
    }

    /** VMA (km/h) déduite d'une course, selon Riegel. */
    public static function vmaRiegel(float $distanceM, float $secondes): float
    {
        return $distanceM / $secondes * 3.6 / (self::pctRiegel($secondes) / 100.0);
    }

    /** Temps (s) projeté sur une distance pour une VMA, selon Riegel (inverse de vmaRiegel). */
    public static function tempsRiegel(float $distanceM, float $vma): float
    {
        $k = self::k();

        return ($distanceM / ($vma / 3.6) * self::DUREE_VMA ** (-$k)) ** (1 / (1 - $k));
    }

    /**
     * Fourchette de VMA (km/h) déduite d'une course et des bornes de la table club.
     * Une borne haute ouverte (« 95+ ») est traitée comme la borne basse.
     *
     * @return array{float, float} [basse, haute]
     */
    public static function vmaTable(float $distanceM, float $secondes, float $pctMin, ?float $pctMax): array
    {
        $vitesse = $distanceM / $secondes * 3.6;
        $pctMax ??= $pctMin;

        return [$vitesse / ($pctMax / 100.0), $vitesse / ($pctMin / 100.0)];
    }

    /**
     * Fourchette de temps (s) projetée sur une distance avec les bornes de la table club.
     *
     * @return array{float, float} [rapide, lent]
     */
    public static function tempsTable(float $distanceM, float $vma, float $pctMin, ?float $pctMax): array
    {
        $pctMax ??= $pctMin;

        return [self::temps($distanceM, $vma, $pctMax), self::temps($distanceM, $vma, $pctMin)];
    }

    /** « 4:05 » (allure s/km) — secondes arrondies. */
    public static function formatAllure(float $secondes): string
    {
        $s = (int) round($secondes);

        return intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }

    /** « 47:45 » sous l'heure, « 1:49:00 » au-delà. */
    public static function formatTemps(float $secondes): string
    {
        $s = (int) round($secondes);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);

        return $h > 0 ? $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).':'.$sec : $m.':'.$sec;
    }

    /** « 13,6 » — VMA au dixième, virgule décimale. */
    public static function formatVma(float $vma): string
    {
        return number_format($vma, 1, ',', '');
    }

    /** Lit « 47:45 », « 1:49:00 » ou « 1h49 » ; null si illisible. */
    public static function parseTemps(string $saisie): ?float
    {
        $t = trim(str_replace(['h', "'", '’', '"'], [':', ':', ':', ''], mb_strtolower($saisie)), ': ');
        if (! preg_match('/^\d{1,2}(:\d{1,2}){0,2}$/', $t)) {
            return null;
        }
        $parts = array_map('intval', explode(':', $t));
        // « 1h49 » donne [1, 49] : heures-minutes si la saisie contenait un h, minutes-secondes sinon.
        if (count($parts) === 2 && str_contains(mb_strtolower($saisie), 'h')) {
            $parts[] = 0;
        }
        $secondes = match (count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            default => $parts[0] * 60,
        };

        return $secondes > 0 ? (float) $secondes : null;
    }
}
