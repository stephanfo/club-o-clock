<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ClubSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

// Dérivation de la catégorie principale d'un athlète (PRD §4.5).
// L'âge de référence est celui atteint sur l'« année sportive » du club : on évalue l'âge à la
// veille du mois de bascule (31 août pour la saison sept→août par défaut). Les bornes
// age_min/age_max des catégories actives sont inclusives et sans chevauchement (validé à la saisie
// côté catalogue) → au plus une catégorie matche.
class AgeCategory
{
    /**
     * Âge de l'athlète pour la saison sportive contenant $on.
     *
     * Le mois d'ouverture vient du réglage club `season_start_month` (§4.17) ; il est lu par défaut
     * mais reste surchargeable par $startMonth, pour que le calcul soit testable sans base et que
     * les appelants qui connaissent déjà le réglage évitent une lecture du singleton.
     * Réf = veille du 1er du mois d'ouverture de la saison SUIVANTE (31 août pour sept→août).
     */
    public static function seasonAge(Carbon $dob, ?Carbon $on = null, ?int $startMonth = null): int
    {
        $on ??= Carbon::now();
        $startMonth ??= self::startMonth();

        $startYear = $on->month >= $startMonth ? $on->year : $on->year - 1;
        $reference = Carbon::create($startYear + 1, $startMonth, 1)->subDay();

        return (int) $dob->copy()->startOfDay()->diffInYears($reference->startOfDay());
    }

    /** Mineur (< 18 ans) au sens de l'âge de saison (§4.2, §4.5). */
    public static function isMinor(Carbon $dob, ?Carbon $on = null, ?int $startMonth = null): bool
    {
        return self::seasonAge($dob, $on, $startMonth) < 18;
    }

    /**
     * Mois d'ouverture de la saison configuré par le club, 9 (septembre) à défaut. Tolère l'absence
     * de table : la dérivation d'âge est appelée par des commandes et des seeders qui peuvent
     * tourner avant que le singleton n'existe.
     */
    private static function startMonth(): int
    {
        try {
            return ClubSettings::current()->season_start_month ?: 9;
        } catch (Throwable) {
            return 9;
        }
    }

    /**
     * Catégorie principale dérivée de la date de naissance pour la saison contenant $on.
     * Renvoie null si aucune catégorie active ne couvre l'âge (cas limite §4.5 : compte sans catégorie).
     *
     * @param  Collection<int,Category>|null  $activeCategories  catalogue déjà chargé (évite une requête)
     */
    public static function derive(Carbon $dob, ?Carbon $on = null, ?Collection $activeCategories = null, ?int $startMonth = null): ?Category
    {
        $age = self::seasonAge($dob, $on, $startMonth);

        $activeCategories ??= Category::query()->whereNull('archived_at')->get();

        return $activeCategories->first(
            fn (Category $c) => $age >= $c->age_min && $age <= $c->age_max
        );
    }
}
