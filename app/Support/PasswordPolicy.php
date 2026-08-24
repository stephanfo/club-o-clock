<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe du club — LIEU UNIQUE de la règle et de son énoncé (§4.1.2).
 *
 * Elle vivait à deux endroits qui avaient divergé : `Password::min(10)` dans FortifyServiceProvider,
 * `Password::min(8)` dans `club:create-admin`, et un écran de réinitialisation qui promettait
 * « Au moins 8 caractères » avant de refuser la saisie à 8. L'utilisateur découvrait la vraie règle
 * par le message d'erreur. Longueur et texte sortent désormais d'ici, donc ils ne peuvent plus
 * s'éloigner l'un de l'autre.
 *
 * Longueur seule, pas de règles de composition (majuscules/chiffres) : contre-productives et
 * contraires aux recommandations ANSSI/NIST — d'où un conseil qui pousse la phrase de passe plutôt
 * que le mot court et tarabiscoté.
 */
class PasswordPolicy
{
    public const MIN = 10;

    /** Règle de validation, unique pour toutes les surfaces (profil, activation, reset, console). */
    public static function rules(): Password
    {
        return Password::min(self::MIN);
    }

    /** Texte d'aide affiché AVANT la saisie — il doit dire la même chose que la règle. */
    public static function hint(): string
    {
        return self::MIN.' caractères minimum. Une phrase de passe longue protège mieux qu’un mot court et compliqué.';
    }

    /** Marque-place court des champs de saisie. */
    public static function placeholder(): string
    {
        return 'Au moins '.self::MIN.' caractères';
    }
}
