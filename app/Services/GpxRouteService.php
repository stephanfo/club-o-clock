<?php

namespace App\Services;

use App\Models\GpxRoute;
use App\Models\User;
use App\Support\GpxStats;
use App\Support\Logging\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Point de passage UNIQUE pour la création et la suppression de parcours GPX (PRD §4.20).
 *
 * Deux chemins d'entrée dans l'app — le formulaire de bibliothèque et la dropzone du formulaire de
 * séance — mais une seule implémentation : le stockage du fichier, le hash de déduplication, la
 * validation des métadonnées et l'audit vivent ici. Le fichier n'est jamais parsé côté serveur
 * (cadrage §7.6) ; hash_file() lit des octets, sans interpréter le XML.
 */
class GpxRouteService
{
    /**
     * Crée un parcours à partir d'un fichier uploadé et des métadonnées extraites côté client.
     *
     * @param  array<string, mixed>  $attributes  name, description, discipline_id, start_location_id, openrunner_*
     * @param  array<string, mixed>|null  $stats  bloc client : métriques + clé `geo`
     */
    public function createFromUpload(UploadedFile $file, array $attributes, ?array $stats, ?User $actor): GpxRoute
    {
        $path = $file->store('gpx', 'local');
        if ($path === false) {
            throw new RuntimeException("Le fichier GPX n'a pas pu être stocké.");
        }

        $route = GpxRoute::create([
            ...$attributes,
            'gpx_path' => $path,
            'gpx_hash' => $this->hash($path),
            'gpx_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'created_by' => $actor?->id,
            ...$this->metricsFrom($stats),
        ]);

        AuditLogger::record('create_gpx_route', $actor, [
            'target_type' => GpxRoute::class,
            'target_id' => $route->id,
        ]);

        return $route;
    }

    /** Remplace le fichier d'un parcours existant ; l'ancien est supprimé du disk. */
    public function replaceGpx(GpxRoute $route, UploadedFile $file, ?array $stats, ?User $actor): GpxRoute
    {
        $old = $route->gpx_path;

        $path = $file->store('gpx', 'local');
        if ($path === false) {
            throw new RuntimeException("Le fichier GPX n'a pas pu être stocké.");
        }

        $route->update([
            'gpx_path' => $path,
            'gpx_hash' => $this->hash($path),
            'gpx_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            ...$this->metricsFrom($stats),
        ]);

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        AuditLogger::record('replace_gpx_route_file', $actor, [
            'target_type' => GpxRoute::class,
            'target_id' => $route->id,
        ]);

        return $route;
    }

    /**
     * Cherche un parcours actif portant exactement le même fichier. Ne détecte que les fichiers
     * binairement identiques : la même trace exportée de Strava et d'OpenRunner ne matchera pas.
     * On signale, on ne bloque pas.
     */
    public function findDuplicateByHash(string $hash, ?int $exceptId = null): ?GpxRoute
    {
        return GpxRoute::query()
            ->active()
            ->where('gpx_hash', $hash)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
    }

    /** Hash d'un fichier encore sur son chemin temporaire (avant création), pour la détection de doublon. */
    public function hashUpload(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    /**
     * Suppression définitive + purge du fichier.
     *
     * Refuse si des séances référencent le parcours : `nullOnDelete` les viderait silencieusement de
     * leur onglet Parcours. L'appelant doit proposer l'archivage à la place.
     */
    public function delete(GpxRoute $route, ?User $actor): void
    {
        if ($route->sessions()->exists()) {
            throw new RuntimeException('Ce parcours est utilisé par au moins une séance : archive-le plutôt que de le supprimer.');
        }

        $path = $route->gpx_path;
        $id = $route->id;
        $route->delete();

        if ($path) {
            Storage::disk('local')->delete($path);
        }

        AuditLogger::record('delete_gpx_route', $actor, [
            'target_type' => GpxRoute::class,
            'target_id' => $id,
        ]);
    }

    /**
     * Archivage (réversible). Le fichier est CONSERVÉ : restaurer un parcours sans sa trace n'aurait
     * aucun sens. Cf. la table du cycle de vie des fichiers (doc J10 §3).
     */
    public function archive(GpxRoute $route, ?User $actor): void
    {
        $route->update(['archived_at' => now(), 'archived_by' => $actor?->id]);

        AuditLogger::record('archive_gpx_route', $actor, [
            'target_type' => GpxRoute::class,
            'target_id' => $route->id,
        ]);
    }

    public function restore(GpxRoute $route, ?User $actor): void
    {
        $route->update(['archived_at' => null, 'archived_by' => null]);

        AuditLogger::record('restore_gpx_route', $actor, [
            'target_type' => GpxRoute::class,
            'target_id' => $route->id,
        ]);
    }

    private function hash(string $path): ?string
    {
        $full = Storage::disk('local')->path($path);

        return is_file($full) ? hash_file('sha256', $full) : null;
    }

    /**
     * Traduit le bloc client (non fiable) en colonnes, via les gardes de GpxStats.
     * Métriques clampées, géo rejetée en bloc si incohérente.
     *
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>
     */
    private function metricsFrom(?array $stats): array
    {
        $clean = GpxStats::sanitize($stats) ?? [];
        $geo = GpxStats::sanitizeGeo(is_array($stats['geo'] ?? null) ? $stats['geo'] : null);

        return [
            'gpx_size_ko' => isset($clean['sizeKo']) ? (int) round($clean['sizeKo']) : null,
            'distance_km' => $clean['distanceKm'] ?? null,
            'dplus_m' => isset($clean['dplus']) ? (int) round($clean['dplus']) : null,
            'dmoins_m' => isset($clean['dmoins']) ? (int) round($clean['dmoins']) : null,
            'alt_min_m' => isset($clean['altMin']) ? (int) round($clean['altMin']) : null,
            'alt_max_m' => isset($clean['altMax']) ? (int) round($clean['altMax']) : null,
            'point_count' => isset($clean['pointCount']) ? (int) round($clean['pointCount']) : null,
            'duration_min' => isset($clean['durationMin']) ? (int) round($clean['durationMin']) : null,
            // Colonnes JSON : valeur explicite obligatoire (MySQL 8.4 interdit DEFAULT sur JSON).
            'polyline' => GpxStats::sanitizePolyline($stats['geo']['polyline'] ?? null),
            'elevation_profile' => GpxStats::sanitizeElevationProfile($stats['geo']['elevationProfile'] ?? null),
            // Géo rejetée en bloc → toutes les colonnes restent nulles, le parcours reste créé.
            'start_lat' => $geo['start_lat'] ?? null,
            'start_lng' => $geo['start_lng'] ?? null,
            'end_lat' => $geo['end_lat'] ?? null,
            'end_lng' => $geo['end_lng'] ?? null,
            'is_loop' => $geo['is_loop'] ?? false,
            'elongation' => $geo['elongation'] ?? null,
            'bbox_min_lat' => $geo['bbox_min_lat'] ?? null,
            'bbox_min_lng' => $geo['bbox_min_lng'] ?? null,
            'bbox_max_lat' => $geo['bbox_max_lat'] ?? null,
            'bbox_max_lng' => $geo['bbox_max_lng'] ?? null,
            'bearing_deg' => $geo['bearing_deg'] ?? null,
            'sector' => $geo['sector'] ?? null,
        ];
    }
}
