<?php

namespace App\Http\Controllers;

use App\Models\ClubSettings;
use App\Support\ClubPalette;
use Illuminate\Http\JsonResponse;

// Manifest PWA dynamique (plan open source OS2) : name/short_name/theme_color reflètent
// l'identité du club en base. Remplace l'ancien fichier statique public/manifest.webmanifest.
// Icônes (public/icons/*.png) restent des fichiers statiques (pas de génération dynamique en V1).
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = ClubSettings::current();

        return response()->json([
            'name' => $settings->name,
            'short_name' => mb_substr($settings->name, 0, 12),
            'description' => "Planning d'entraînement du club",
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $settings->primary_color ?: ClubPalette::DEFAULTS['primary_color'],
            'lang' => 'fr',
            'icons' => [
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ])
            ->header('Content-Type', 'application/manifest+json')
            // Le <link rel="manifest"> est dans head-meta, donc sur TOUTES les pages : sans cache,
            // chaque récupération paie un démarrage complet du framework et une requête SQL sur le
            // mutualisé, pour un JSON qui ne bouge qu'à l'édition des paramètres du club. Un jour
            // de péremption est sans conséquence pour un nom et une couleur (c'était un fichier
            // statique auparavant).
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
