<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ClubSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

// Arithmétique d'âge du projet. Deux questions, deux références — les confondre a produit la
// friction consignée au carnet le 2026-09-04 (doc/RETOURS_TERRAIN.md) :
//
//   1. « Quel âge a-t-il POUR LA COMPÉTITION ? » → seasonAge() / derive() (PRD §4.5). L'âge de
//      référence est celui atteint sur l'« année sportive » du club : on l'évalue à la veille du
//      mois de bascule (31 août pour la saison sept→août par défaut), de sorte qu'un athlète court
//      toute la saison dans la catégorie de l'âge qu'il y atteindra. Les bornes age_min/age_max des
//      catégories actives sont inclusives et sans chevauchement (validé à la saisie côté catalogue)
//      → au plus une catégorie matche.
//   2. « Est-il LÉGALEMENT MINEUR ? » → legalAge() / isLegallyMinor() (PRD §4.2, tutelle). L'âge
//      réel au jour dit, sans anticipation : la minorité est un fait juridique, pas une convention
//      sportive. Un adhérent né en août est mineur jusqu'à son anniversaire, quand bien même l'âge
//      de saison le compte déjà majeur onze mois plus tôt.
//
// Toute garde de tutelle interroge (2). Toute question de catégorie interroge (1).
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

    /**
     * Âge réel révolu au jour dit — celui de l'état civil, sans référence de saison.
     *
     * C'est la seule mesure qui répond à « cette personne est-elle mineure ? ». seasonAge() ne le
     * peut pas : sa référence est postérieure de plusieurs mois, elle déclare donc majeur quelqu'un
     * qui ne l'est pas encore.
     */
    public static function legalAge(Carbon $dob, ?Carbon $on = null): int
    {
        $on ??= Carbon::now();

        return (int) $dob->copy()->startOfDay()->diffInYears($on->copy()->startOfDay());
    }

    /** Minorité légale (< 18 ans révolus) au jour dit. Gouverne la tutelle (§4.2). */
    public static function isLegallyMinor(Carbon $dob, ?Carbon $on = null): bool
    {
        return self::legalAge($dob, $on) < 18;
    }

    /**
     * Date de naissance à partir de laquelle on est encore légalement mineur au jour dit : née
     * APRÈS ce jour-là, la personne a moins de 18 ans révolus. Sert aux requêtes SQL, qui ne
     * peuvent pas appeler isLegallyMinor() ligne à ligne (cf. les scopes du modèle User).
     *
     * subYearsNoOverflow() et non subYears() : le 29 février, ce dernier reporte au 1er mars —
     * 2028-02-29 moins 18 ans donnait 2010-03-01 —, et le seuil rangeait alors parmi les MAJEURS
     * quelqu'un né le 1er mars 2010, que legalAge() compte à 17 ans ce jour-là. Un jour tous les
     * quatre ans, le SQL et le calcul PHP se contredisaient, et le formulaire proposait ce mineur
     * comme parent garant — précisément ce que link() interdit.
     */
    public static function minorityThreshold(?Carbon $on = null): Carbon
    {
        return ($on ?? Carbon::now())->copy()->startOfDay()->subYearsNoOverflow(18);
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
