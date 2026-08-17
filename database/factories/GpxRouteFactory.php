<?php

namespace Database\Factories;

use App\Models\GpxRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GpxRoute>
 */
class GpxRouteFactory extends Factory
{
    /**
     * Parcours par défaut : une boucle vélo cohérente autour de Blois, avec un bloc géo complet.
     * Les colonnes JSON reçoivent une valeur EXPLICITE (MySQL 8.4 interdit DEFAULT sur JSON).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Boucle Loire',
            'description' => null,
            'discipline_id' => null,
            'gpx_path' => 'gpx/'.$this->faker->uuid().'.gpx',
            'gpx_hash' => hash('sha256', $this->faker->uuid()),
            'gpx_original_name' => 'boucle-loire.gpx',
            'gpx_size_ko' => 42,
            'distance_km' => 42.5,
            'dplus_m' => 320,
            'dmoins_m' => 315,
            'alt_min_m' => 62,
            'alt_max_m' => 148,
            'point_count' => 1840,
            'duration_min' => 95,
            'start_lat' => 47.5860000,
            'start_lng' => 1.3350000,
            'end_lat' => 47.5861000,
            'end_lng' => 1.3351000,
            'is_loop' => true,
            'elongation' => 1.20,   // circuit arrondi (< GpxRoute::ELONGATION_THRESHOLD)
            'bbox_min_lat' => 47.5500000,
            'bbox_min_lng' => 1.3000000,
            'bbox_max_lat' => 47.6200000,
            'bbox_max_lng' => 1.4000000,
            'bearing_deg' => 45,
            'sector' => 'NE',
            'polyline' => [[47.586, 1.335], [47.600, 1.360], [47.610, 1.380], [47.586, 1.335]],
            'elevation_profile' => [[0.0, 62.0], [10.5, 120.0], [21.0, 148.0], [42.5, 64.0]],
            'openrunner_embed_url' => null,
            'openrunner_public_url' => null,
            'start_location_id' => null,
            'created_by' => User::factory()->coach(),
            'archived_at' => null,
            'archived_by' => null,
        ];
    }

    /** Parcours archivé : masqué de la bibliothèque, mais son fichier est conservé (doc J10 §3). */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
            'archived_by' => User::factory()->admin(),
        ]);
    }

    /** Parcours sans aucune donnée géographique (bloc géo rejeté par la garde serveur). */
    public function withoutGeo(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_lat' => null, 'start_lng' => null, 'end_lat' => null, 'end_lng' => null,
            'is_loop' => false,
            'bbox_min_lat' => null, 'bbox_min_lng' => null, 'bbox_max_lat' => null, 'bbox_max_lng' => null,
            'bearing_deg' => null, 'sector' => null, 'elongation' => null,
            'polyline' => null, 'elevation_profile' => null,
        ]);
    }

    /** Circuit étiré (≥ seuil) plutôt qu'arrondi. */
    public function elongated(): static
    {
        return $this->state(fn (array $attributes) => ['elongation' => 2.30]);
    }

    /** Positionne le parcours dans un secteur cardinal donné, cap et bbox cohérents. */
    public function sector(string $sector, int $bearing): static
    {
        return $this->state(fn (array $attributes) => [
            'sector' => $sector,
            'bearing_deg' => $bearing,
        ]);
    }
}
