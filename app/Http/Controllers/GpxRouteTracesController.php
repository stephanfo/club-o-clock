<?php

namespace App\Http\Controllers;

use App\Livewire\GpxRouteLibrary;
use App\Models\GpxRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tracés simplifiés de la bibliothèque, pour la carte d'ensemble (J10.C bis, PRD §4.20).
 *
 * Endpoint séparé plutôt que payload Livewire : le corpus du club pèse déjà ~69 Ko de polylines
 * (18 parcours × 200 points). Embarquées dans le HTML, elles seraient re-sérialisées à CHAQUE
 * frappe de recherche et à chaque bascule de chip — Livewire renvoie l'intégralité du state à
 * chaque requête. Ici la carte fetch une fois par jeu de filtres, et seulement en mode carte.
 *
 * Aucun parsing GPX : on ne relit pas les fichiers, on sert la colonne `polyline` déjà extraite
 * côté client au dépôt (cadrage §7.6 — le serveur ne parse jamais de GPX).
 */
class GpxRouteTracesController extends Controller
{
    /**
     * Plafond de tracés renvoyés. Au-delà la carte devient illisible (des dizaines de traits
     * superposés sur le même secteur) autant qu'elle devient lourde. Le compteur renvoyé permet
     * à la vue de signaler la troncature plutôt que de mentir par omission.
     */
    public const MAX_TRACES = 120;

    public function index(Request $request): JsonResponse
    {
        // Gate::authorize et non $this->authorize() : le Controller de base du projet n'embarque pas
        // le trait AuthorizesRequests (cf. app/Http/Controllers/Controller.php).
        Gate::authorize('viewAny', GpxRoute::class);

        // Les filtres sont RÉAPPLIQUÉS ici, à partir des mêmes paramètres que la bibliothèque :
        // le contrôleur ne fait pas confiance à une liste d'ids fournie par le client, et la carte
        // reste cohérente avec la liste sans que Livewire ait à pousser quoi que ce soit.
        $library = new GpxRouteLibrary;
        $library->search = (string) $request->query('search', '');
        $library->sector = self::strings($request->query('sector'));
        $library->discipline = self::strings($request->query('discipline'));
        $library->shape = self::strings($request->query('shape'));
        $library->grade = self::strings($request->query('grade'));
        $library->distance = self::strings($request->query('distance'));
        $library->archived = $request->boolean('archived');

        // On demande UNE ligne de plus que le plafond : à exactement MAX_TRACES résultats, rien n'a
        // été coupé et l'avertissement « affichage limité » serait un mensonge. La ligne surnuméraire
        // est la seule preuve qu'il reste quelque chose derrière ; elle est retirée avant l'envoi.
        $routes = $library->tracesQuery()
            ->with('discipline')
            ->take(self::MAX_TRACES + 1)
            ->get();

        $truncated = $routes->count() > self::MAX_TRACES;
        $routes = $routes->take(self::MAX_TRACES);

        return response()->json([
            'truncated' => $truncated,
            'routes' => $routes->map(fn (GpxRoute $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'url' => route('gpx-routes.show', $r),
                'discipline' => $r->discipline?->label,
                'sector' => $r->sector,
                'distanceKm' => $r->distance_km !== null ? (float) $r->distance_km : null,
                'dplus' => $r->dplus_m,
                'grade' => $r->gradeLabel(),
                'points' => $r->polyline,
            ])->values(),
        ]);
    }

    /**
     * Normalise un paramètre d'URL en liste de chaînes.
     *
     * `?sector[]=N&sector[]=NE` arrive en array, `?sector=N` en scalaire, et un client hostile peut
     * envoyer n'importe quoi (array imbriqué, objet). On aplatit à des scalaires et on jette le
     * reste ; les valeurs elles-mêmes sont ensuite filtrées par la liste blanche des scopes.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn ($v) => is_scalar($v))
            ->map(fn ($v) => (string) $v)
            ->filter(fn (string $v) => $v !== '')
            ->values()
            ->all();
    }
}
