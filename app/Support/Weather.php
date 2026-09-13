<?php

namespace App\Support;

// Interprétation des codes météo WMO d'Open-Meteo (PRD §4.13.5) : pictogramme + libellé FR.
// Icônes limitées au jeu Lucide porté (sun / cloud-sun / cloud / cloud-rain).
class Weather
{
    /** @var array<int, array{0:string,1:string}> code WMO => [icône, libellé] */
    private const MAP = [
        0 => ['sun', 'Ciel dégagé'],
        1 => ['cloud-sun', 'Plutôt dégagé'],
        2 => ['cloud-sun', 'Partiellement nuageux'],
        3 => ['cloud', 'Couvert'],
        45 => ['cloud', 'Brouillard'],
        48 => ['cloud', 'Brouillard givrant'],
        51 => ['cloud-rain', 'Bruine légère'],
        53 => ['cloud-rain', 'Bruine'],
        55 => ['cloud-rain', 'Bruine dense'],
        56 => ['cloud-rain', 'Bruine verglaçante'],
        57 => ['cloud-rain', 'Bruine verglaçante'],
        61 => ['cloud-rain', 'Pluie faible'],
        63 => ['cloud-rain', 'Pluie'],
        65 => ['cloud-rain', 'Pluie forte'],
        66 => ['cloud-rain', 'Pluie verglaçante'],
        67 => ['cloud-rain', 'Pluie verglaçante'],
        71 => ['cloud', 'Neige faible'],
        73 => ['cloud', 'Neige'],
        75 => ['cloud', 'Neige forte'],
        77 => ['cloud', 'Grésil'],
        80 => ['cloud-rain', 'Averses faibles'],
        81 => ['cloud-rain', 'Averses'],
        82 => ['cloud-rain', 'Fortes averses'],
        85 => ['cloud', 'Averses de neige'],
        86 => ['cloud', 'Averses de neige'],
        95 => ['cloud-rain', 'Orage'],
        96 => ['cloud-rain', 'Orage avec grêle'],
        99 => ['cloud-rain', 'Orage avec grêle'],
    ];

    /**
     * Rang de sévérité (#55). La numérotation WMO n'est **pas** un ordre de sévérité : un `max()`
     * naïf ferait passer « Neige forte » (75) derrière « Averses faibles » (80). D'où ce rang
     * explicite — dégagé < nuageux < brouillard < bruine < pluie < averses < neige < orage —, les
     * unités ordonnant l'intensité au sein d'une même famille.
     *
     * L'issue listait « neige < averses » ; c'est inconciliable avec l'exigence qu'elle pose
     * elle-même (« Neige forte » doit l'emporter sur « Averses faibles »), la neige étant alors
     * dominée par toute la famille des averses. Toute neige passe donc devant toute pluie : elle
     * fait changer d'équipement, ou renoncer, là où une averse fait prendre une veste.
     *
     * @var array<int, int> code WMO => rang
     */
    private const SEVERITY = [
        0 => 0,
        1 => 10, 2 => 11, 3 => 12,
        45 => 20, 48 => 21,
        51 => 30, 53 => 31, 55 => 32, 56 => 33, 57 => 34,
        61 => 40, 63 => 41, 65 => 42, 66 => 43, 67 => 44,
        80 => 50, 81 => 51, 82 => 52,
        71 => 60, 73 => 61, 75 => 62, 77 => 63, 85 => 64, 86 => 65,
        95 => 70, 96 => 71, 99 => 72,
    ];

    /** Rang de sévérité d'un code. Un code inconnu vaut -1 : il ne l'emporte sur rien de connu. */
    public static function severity(?int $code): int
    {
        return self::SEVERITY[$code] ?? -1;
    }

    /**
     * Code le plus sévère d'une fenêtre — c'est lui qui fait changer d'équipement ou renoncer.
     * Égalité : le premier rencontré l'emporte. Aucun code exploitable => null.
     *
     * @param  array<int, ?int>  $codes
     */
    public static function worst(array $codes): ?int
    {
        $pire = null;
        foreach ($codes as $code) {
            if ($code === null) {
                continue;
            }
            if ($pire === null || self::severity($code) > self::severity($pire)) {
                $pire = $code;
            }
        }

        return $pire;
    }

    public static function icon(?int $code): string
    {
        return self::MAP[$code][0] ?? 'cloud';
    }

    public static function label(?int $code): string
    {
        return self::MAP[$code][1] ?? 'Prévision';
    }

    /** Direction du vent (degrés → cardinal FR). */
    public static function direction(?int $deg): string
    {
        if ($deg === null) {
            return '';
        }
        $points = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

        return $points[(int) round(($deg % 360) / 45) % 8];
    }
}
