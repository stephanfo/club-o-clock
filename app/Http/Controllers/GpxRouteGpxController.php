<?php

namespace App\Http\Controllers;

use App\Models\GpxRoute;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Téléchargement du fichier d'un parcours (PRD §4.20). Visible à tous les membres connectés
// (cohérence §4.12 : aucune info de fiche restreinte aux inscrits). Servi hors webroot via la voie HTTP.
// Remplace SessionGpxController : le GPX n'appartient plus à une séance mais à une GpxRoute partagée.
class GpxRouteGpxController extends Controller
{
    public function download(GpxRoute $gpxRoute): StreamedResponse
    {
        // Accès = tout membre connecté (route sous middleware auth+verified ; GpxRoutePolicy::view
        // est ouvert à tous). Pas de gate supplémentaire ici, comme l'ancien SessionGpxController.
        abort_unless(
            $gpxRoute->gpx_path && Storage::disk('local')->exists($gpxRoute->gpx_path),
            404
        );

        // Le nom vit sur le modèle : les vues le répètent dans l'attribut `download` du lien, et les
        // deux doivent concorder (cf. GpxRoute::downloadFilename).
        return Storage::disk('local')->download($gpxRoute->gpx_path, $gpxRoute->downloadFilename(), [
            'Content-Type' => 'application/gpx+xml',
        ]);
    }
}
