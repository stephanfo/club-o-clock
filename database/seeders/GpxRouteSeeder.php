<?php

namespace Database\Seeders;

use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Bibliothèque de parcours de démo (PRD §4.20).
 *
 * 16 traces de démonstration (sorties vélo du dimanche), deux par secteur cardinal, de 48 à 86 km.
 * Elles sont ANONYMISÉES : tronquées d'un kilomètre à chaque extrémité — ce qui supprime le point
 * de départ d'origine — puis recollées sur un lieu public (le parking de la piscine), qui devient
 * le départ et l'arrivée commun de toute la bibliothèque.
 * Les fichiers vivent dans database/seeders/fixtures/gpx/ et sont copiés sur le disk `local`.
 *
 * Les métriques (distance, D+, bbox, cap, secteur, polyline simplifiée, profil altimétrique) sont
 * PRÉ-CALCULÉES dans fixtures/gpx-routes.json : le serveur ne parse jamais un GPX (cadrage §7.6),
 * pas même au seed. Le JSON a été produit hors app en rejouant la logique de resources/js/gpx.js.
 *
 * Idempotent : un parcours déjà présent (même nom) n'est pas recréé.
 */
class GpxRouteSeeder extends Seeder
{
    public function run(): void
    {
        $fixtureDir = database_path('seeders/fixtures/gpx');
        $manifest = database_path('seeders/fixtures/gpx-routes.json');

        if (! is_file($manifest)) {
            $this->command?->warn('GpxRouteSeeder : manifeste absent, aucun parcours créé.');

            return;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($manifest), true) ?: [];

        // Les traces sont toutes des sorties vélo ; discipline optionnelle côté modèle, mais la
        // renseigner rend le filtre discipline démontrable dès le seed.
        $velo = Discipline::where('label', 'Vélo')->first();

        // Auteur : un coach existant si le DemoSeeder est passé avant, sinon on laisse null
        // (created_by est nullable — invariant RGPD).
        $author = User::query()->whereJsonContains('roles', 'coach')->first();

        $created = 0;
        foreach ($rows as $row) {
            $source = $fixtureDir.'/'.$row['file'];
            if (! is_file($source)) {
                $this->command?->warn("GpxRouteSeeder : fixture manquante — {$row['file']}");

                continue;
            }

            if (GpxRoute::where('name', $row['name'])->exists()) {
                continue;
            }

            // Copie sur le disk applicatif (hors webroot), sous le même préfixe que les uploads.
            $path = 'gpx/'.$row['file'];
            Storage::disk('local')->put($path, (string) file_get_contents($source));

            GpxRoute::create([
                'name' => $row['name'],
                'description' => $this->describe($row),
                'discipline_id' => $velo?->id,
                'gpx_path' => $path,
                'gpx_hash' => hash_file('sha256', $source),
                'gpx_original_name' => $row['file'],
                'gpx_size_ko' => $row['gpx_size_ko'],
                'distance_km' => $row['distance_km'],
                'dplus_m' => $row['dplus_m'],
                'dmoins_m' => $row['dmoins_m'],
                'alt_min_m' => $row['alt_min_m'],
                'alt_max_m' => $row['alt_max_m'],
                'point_count' => $row['point_count'],
                'duration_min' => $row['duration_min'],
                'start_lat' => $row['start_lat'],
                'start_lng' => $row['start_lng'],
                'end_lat' => $row['end_lat'],
                'end_lng' => $row['end_lng'],
                'is_loop' => $row['is_loop'],
                'elongation' => $row['elongation'],
                'bbox_min_lat' => $row['bbox_min_lat'],
                'bbox_min_lng' => $row['bbox_min_lng'],
                'bbox_max_lat' => $row['bbox_max_lat'],
                'bbox_max_lng' => $row['bbox_max_lng'],
                'bearing_deg' => $row['bearing_deg'],
                'sector' => $row['sector'],
                // Valeur EXPLICITE obligatoire : MySQL 8.4 interdit DEFAULT sur une colonne JSON.
                'polyline' => $row['polyline'],
                'elevation_profile' => $row['elevation_profile'],
                'created_by' => $author?->id,
            ]);
            $created++;
        }

        $this->attachToSessions();

        $this->command?->info(sprintf(
            'GpxRouteSeeder : %d parcours créés (%d en bibliothèque, %d secteurs couverts).',
            $created,
            GpxRoute::count(),
            GpxRoute::query()->whereNotNull('sector')->distinct()->count('sector'),
        ));
    }

    /** Description courte : forme du circuit + relief, dans le vocabulaire du club. */
    private function describe(array $row): string
    {
        $shape = ($row['elongation'] ?? 0) >= GpxRoute::ELONGATION_THRESHOLD
            ? 'Circuit étiré'
            : 'Circuit arrondi';

        return sprintf(
            '%s au départ du parking de la piscine, %s km pour %s m de dénivelé positif.',
            $shape,
            $row['distance_km'],
            $row['dplus_m'],
        );
    }

    /**
     * Rattache quelques parcours à des séances vélo à venir, pour peupler « séances où ce parcours
     * a été utilisé » sur la fiche et démontrer la réutilisation (un même parcours sur N séances).
     */
    private function attachToSessions(): void
    {
        $routes = GpxRoute::query()->orderBy('id')->take(3)->get();
        if ($routes->isEmpty()) {
            return;
        }

        $sessions = Session::query()
            ->whereNull('route_id')
            ->whereHas('discipline', fn ($q) => $q->where('label', 'Vélo'))
            ->orderBy('start_at')
            ->take(6)
            ->get();

        foreach ($sessions as $i => $session) {
            // Cyclique : les 3 premiers parcours se partagent les 6 séances → chacun en sert 2.
            $session->update(['route_id' => $routes[$i % $routes->count()]->id]);
        }
    }
}
