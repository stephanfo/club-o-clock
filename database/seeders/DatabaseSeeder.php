<?php

namespace Database\Seeders;

use App\Models\ClubSettings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Singleton ClubSettings (PRD §4.17) — défauts FR.
        ClubSettings::current();

        // Catalogues seedés au déploiement (PRD §4.5, §4.6).
        $this->call(CatalogSeeder::class);
    }
}
