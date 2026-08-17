<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Qualification;
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
