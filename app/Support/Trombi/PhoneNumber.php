<?php

namespace App\Support\Trombi;

/**
 * Téléphone du trombinoscope (PRD §4.23.2, cadrage §7.18) : normalisation maison en E.164, sans
 * libphonenumber — une dépendance lourde pour un seul champ. Le numéro sert à se joindre entre
 * adhérents (lien `tel:`), jamais à un envoi de l'application.
 */
final class PhoneNumber
{
    /**
     * `06 12 34 56 78` → `+33612345678` ; `+44 20…` et `0044 20…` acceptés tels quels.
     * Null si la saisie n'est pas un numéro plausible.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[^\d\s.\-()+\/]/', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');

        if ($international) {
            $digits = str_starts_with($raw, '+') ? $digits : substr($digits, 2);

            // E.164 : 15 chiffres au plus, indicatif de pays sans zéro de tête.
            return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+'.$digits : null;
        }

        // National français : 10 chiffres commençant par 0, hors 00.
        return preg_match('/^0[1-9]\d{8}$/', $digits) ? '+33'.substr($digits, 1) : null;
    }

    /** Affichage groupé par paires : `06 12 34 56 78` pour la France, `+44 20 79 46 09 58` sinon. */
    public static function format(string $e164): string
    {
        if (str_starts_with($e164, '+33') && strlen($e164) === 12) {
            return trim(chunk_split('0'.substr($e164, 3), 2, ' '));
        }

        return '+'.trim(chunk_split(ltrim($e164, '+'), 2, ' '));
    }
}
