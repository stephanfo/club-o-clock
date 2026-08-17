<?php

namespace App\Support;

use App\Models\ClubSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Génère la surcharge CSS des tokens de marque (--brand/--accent/--info) à partir des couleurs
 * personnalisées en base (plan open source OS2 : personnalisation niveau intermédiaire, color
 * pickers admin plutôt qu'un fichier custom.css d'instance). Rien à générer si le club n'a
 * personnalisé aucune couleur : la palette neutre par défaut de tokens.css s'applique telle quelle.
 *
 * Les déclinaisons (-50…-900) sont dérivées de la teinte choisie par mélange avec blanc/noir
 * (mêmes proportions que la palette par défaut de tokens.css) : le CSS applicatif s'appuie
 * largement sur ces déclinaisons (fonds clairs, hover), pas seulement sur la teinte principale.
 */
class ClubPalette
{
    /** @var array<string, string> */
    private const TOKENS = [
        'primary_color' => 'brand',
        'accent_color' => 'accent',
        'info_color' => 'info',
    ];

    /**
     * Couleurs de démarrage (palette neutre par défaut de club-tokens.css --brand/--accent/--info).
     * Utilisées pour préremplir l'affichage des color pickers admin tant que le club n'a rien
     * personnalisé (colonnes NULL en base) : sans ça, un <input type="color"> vide affiche noir
     * par défaut, laissant croire à tort que la palette active est noire.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'primary_color' => '#69bf2d',
        'accent_color' => '#e4027d',
        'info_color' => '#3c69bb',
    ];

    /**
     * Format hexadécimal accepté pour une couleur personnalisée — partagé par la validation du
     * formulaire admin et par overrideCss(). Une seule définition : si le format s'élargit un jour
     * (hex court, alpha), le formulaire ne peut pas accepter des valeurs que le générateur ignore,
     * ce qui ferait enregistrer à l'admin une couleur qui ne s'affiche jamais.
     */
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Poids de mélange par déclinaison : positif = mélange vers le blanc (plus clair que la
     * teinte de base), négatif = mélange vers le noir (plus sombre). 0 = teinte de base.
     *
     * @var array<int|string, float>
     */
    private const SHADES = [
        '50' => 0.92, '100' => 0.8, '200' => 0.6, '300' => 0.4, '400' => 0.2,
        '' => 0.0,
        '600' => -0.15, '700' => -0.3, '800' => -0.5, '900' => -0.7,
    ];

    /** Clé de cache du CSS généré — purgée par ClubSettings::flushCache() à chaque enregistrement. */
    public const CACHE_KEY = 'club-palette-css';

    /**
     * Bloc <style> à injecter après tokens.css, ou chaîne vide si aucune couleur personnalisée.
     *
     * Mémorisé : c'est une fonction pure de trois colonnes qui ne changent qu'à l'enregistrement
     * des paramètres, alors que le calcul (3 tokens × 10 mélanges + luminances corrigées en gamma)
     * était refait à CHAQUE requête des deux layouts — coût non négligeable sur mutualisé.
     */
    public static function overrideCss(ClubSettings $settings): string
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::buildCss($settings));
    }

    private static function buildCss(ClubSettings $settings): string
    {
        $declarations = [];
        foreach (self::TOKENS as $attribute => $token) {
            $value = $settings->{$attribute};
            if (! $value || ! self::isValidHex($value)) {
                continue;
            }
            foreach (self::SHADES as $shade => $weight) {
                $name = $shade === '' ? "--{$token}" : "--{$token}-{$shade}";
                $shadeValue = $weight === 0.0 ? $value : self::mix($value, $weight);
                $declarations[] = "  {$name}: {$shadeValue};";
            }

            // Texte/icône posé sur un aplat de CETTE couleur (bouton primaire, pastilles de
            // discipline, badges…). Les valeurs par défaut de tokens.css sont calibrées pour la
            // palette d'origine ; toute autre teinte les rend fausses, dans un sens ou dans
            // l'autre — une primaire sombre (indigo) avale du texte noir, un accent clair
            // (ambre) avale du blanc. Chaque token porte donc SA couleur de premier plan,
            // calculée par contraste WCAG.
            $declarations[] = '  --fg-on-'.$token.': '.self::readableTextOn($value).';';

            // Alias historique du design system d'origine, conservé pour ne pas casser les
            // règles qui l'utilisent encore.
            if ($token === 'brand') {
                $declarations[] = '  --fg-on-primary: '.self::readableTextOn($value).';';
            }
        }

        if ($declarations === []) {
            return '';
        }

        return ":root {\n".implode("\n", $declarations)."\n}";
    }

    /**
     * Noir ou blanc, selon celui qui contraste le mieux avec $hex (WCAG 2.x).
     *
     * Les deux ratios sont comparés plutôt qu'un seuil de luminance fixe : le seuil correct
     * dépend de la couleur de l'encre (ici --ink #0a0a0a, pas un noir pur), et une comparaison
     * directe évite d'avoir à le recalculer si l'encre change.
     */
    private static function readableTextOn(string $hex): string
    {
        $onInk = self::contrastRatio($hex, '#0a0a0a');
        $onPaper = self::contrastRatio($hex, '#ffffff');

        return $onInk >= $onPaper ? 'var(--ink)' : 'var(--paper)';
    }

    /** Ratio de contraste WCAG entre deux couleurs (1 = identiques, 21 = noir sur blanc). */
    private static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Luminance relative WCAG d'une couleur hex. */
    private static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) $r) + 0.7152 * $channel((int) $g) + 0.0722 * $channel((int) $b);
    }

    private static function isValidHex(string $value): bool
    {
        return (bool) preg_match(self::HEX_PATTERN, $value);
    }

    /** Mélange $hex vers blanc ($weight > 0) ou noir ($weight < 0), proportionnellement à |$weight|. */
    private static function mix(string $hex, float $weight): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $target = $weight > 0 ? 255 : 0;
        $ratio = abs($weight);

        $blend = fn (int $c): int => (int) round($c + ($target - $c) * $ratio);

        return sprintf('#%02x%02x%02x', $blend($r), $blend($g), $blend($b));
    }
}
