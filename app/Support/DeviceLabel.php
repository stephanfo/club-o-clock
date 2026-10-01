<?php

namespace App\Support;

// Étiquette appareil heuristique à partir du User-Agent (présentation, best-effort) : sessions
// actives et appareils abonnés au push (#97) de l'onglet profil.
final class DeviceLabel
{
    public static function from(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'Appareil inconnu';
        }

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Appareil',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Navigateur',
        };

        return "{$os} · {$browser}";
    }
}
