<?php

namespace App\Support\Allures;

/**
 * Référentiel d'allures d'une discipline (#114). Il dit quelle valeur de référence le membre
 * renseigne et quelles grilles s'appliquent. V1 : course à pied (VMA) seulement. Le vélo (% FTP,
 * watts) et la natation (% CSS, allure /100 m) s'ajouteront ici sans toucher au schéma.
 */
enum Referentiel: string
{
    case Course = 'course';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Course à pied',
        };
    }

    /** Nom court de la valeur de référence. */
    public function referenceLabel(): string
    {
        return match ($this) {
            self::Course => 'VMA',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Course => 'km/h',
        };
    }

    /**
     * Bornes de vraisemblance de la valeur de référence. Hors bornes : saisie refusée,
     * estimation signalée « improbable ».
     *
     * @return array{float, float}
     */
    public function bounds(): array
    {
        return match ($this) {
            self::Course => [8.0, 25.0],
        };
    }

    /**
     * Distances de course de la projection et de la table club : clé => [libellé, mètres].
     *
     * @return array<string, array{string, float}>
     */
    public function distances(): array
    {
        return match ($this) {
            self::Course => [
                '5k' => ['5 km', 5000.0],
                '10k' => ['10 km', 10000.0],
                'semi' => ['Semi', 21097.5],
                'marathon' => ['Marathon', 42195.0],
            ],
        };
    }

    /**
     * Distances du tableau « Mes allures par zone », de la piste au marathon : libellé => mètres.
     *
     * @return array<string, float>
     */
    public function gridDistances(): array
    {
        return match ($this) {
            self::Course => [
                '100 m' => 100.0, '200 m' => 200.0, '300 m' => 300.0, '400 m' => 400.0,
                '500 m' => 500.0, '600 m' => 600.0, '800 m' => 800.0, '1 km' => 1000.0,
                '1,5 km' => 1500.0, '2 km' => 2000.0, '3 km' => 3000.0, '5 km' => 5000.0,
                '10 km' => 10000.0, 'Semi' => 21097.5, 'Marathon' => 42195.0,
            ],
        };
    }

    /**
     * Grille de zones générique posée au déploiement. Volontairement neutre (littérature, pas la
     * grille d'un coach) : chaque club saisit la sienne dans l'admin, sur son instance.
     *
     * @return list<array{string, string, int, int}> [code, libellé, % min, % max]
     */
    public function defaultZones(): array
    {
        return match ($this) {
            self::Course => [
                ['Z1', 'Endurance', 60, 75],
                ['Z2', 'Endurance active', 75, 85],
                ['Z3', 'Seuil', 85, 90],
                ['Z4', 'Fractionné long', 90, 95],
                ['Z5', 'VMA', 95, 105],
            ],
        };
    }

    /** @return array<string, string> valeur => libellé, pour les sélecteurs. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $r) {
            $out[$r->value] = $r->label();
        }

        return $out;
    }
}
