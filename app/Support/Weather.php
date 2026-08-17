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
