<?php

namespace Database\Seeders;

use App\Models\AllureLevel;
use App\Models\AllureZone;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Qualification;
use App\Support\Allures\Referentiel;
use Illuminate\Database\Seeder;

// Seed des catalogues au déploiement (PRD §4.5, §4.6). Entièrement reconfigurable par l'admin ensuite.
// Idempotent : firstOrCreate sur la clé naturelle, rejouable sans doublon.
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Disciplines (PRD §4.6, ligne « Disciplines »).
        $disciplines = ['Natation', 'Course à pied', 'Vélo', 'Enchaînement', 'PPG', 'Autre'];
        foreach ($disciplines as $i => $label) {
            Discipline::firstOrCreate(['label' => $label], ['sort_order' => $i]);
        }
        // Allures course (#114) : référentiel de la course à pied + grille de zones générique.
        // Les coefficients du coach se saisissent dans l'admin de l'instance, jamais ici.
        Discipline::where('label', 'Course à pied')->whereNull('referentiel')->update(['referentiel' => Referentiel::Course->value]);
        // Seulement sur catalogue vide : rejoué après que le coach a posé sa grille, le seed
        // ferait chevaucher ses zones.
        if (! AllureZone::where('referentiel', Referentiel::Course->value)->exists()) {
            foreach (Referentiel::Course->defaultZones() as [$code, $label, $min, $max, $aliases]) {
                AllureZone::create([
                    'referentiel' => Referentiel::Course->value, 'code' => $code,
                    'label' => $label, 'aliases' => $aliases, 'pct_min' => $min, 'pct_max' => $max,
                ]);
            }
        }
        // Le modèle de Riegel, niveau d'office de la table des allures cibles (comme la migration).
        AllureLevel::firstOrCreate(
            ['referentiel' => Referentiel::Course->value, 'model' => AllureLevel::MODEL_RIEGEL],
            ['label' => AllureLevel::LABEL_RIEGEL, 'sort_order' => 0, 'active' => true],
        );

        // Types d'épreuve (PRD §4.6).
        $eventTypes = ['Triathlon', 'Duathlon', 'Aquathlon', 'Course à pied', 'Trail', 'Autre'];
        foreach ($eventTypes as $i => $label) {
            EventType::firstOrCreate(['label' => $label], ['sort_order' => $i]);
        }

        // Qualifications coach (PRD §4.6).
        $qualifications = [
            ['BF1', 'BF1'], ['BF2', 'BF2'], ['BF3', 'BF3'], ['BF4', 'BF4'], ['BF5', 'BF5'],
            ['BNSSA', 'BNSSA'], ['MNS', 'MNS'], ['PSC1', 'PSC1'], ['PSE1', 'PSE1'], ['AFPS', 'AFPS'],
        ];
        foreach ($qualifications as $i => [$label, $code]) {
            Qualification::firstOrCreate(['label' => $label], ['code' => $code, 'sort_order' => $i]);
        }

        // Catégories d'âge — référentiel FFTri (PRD §4.5). Bornes inclusives, pas de chevauchement.
        $categories = [
            ['Mini-poussins', 6, 7],
            ['Poussins', 8, 9],
            ['Pupilles', 10, 11],
            ['Benjamins', 12, 13],
            ['Minimes', 14, 15],
            ['Cadets', 16, 17],
            ['Juniors', 18, 19],
            ['Adulte', 20, 39],
            ['Master', 40, 120],
        ];
        foreach ($categories as $i => [$label, $min, $max]) {
            Category::firstOrCreate(
                ['label' => $label],
                ['age_min' => $min, 'age_max' => $max, 'sort_order' => $i],
            );
        }

        // Tags de quota : AUCUN au seed (PRD §4.6) — l'admin les crée selon les besoins du club.
    }
}
