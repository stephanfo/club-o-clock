<?php

namespace App\Http\Controllers;

use App\Models\ClubSettings;
use App\Support\ClubPalette;
use Illuminate\Http\JsonResponse;

// Manifest PWA dynamique (plan open source OS2) : name/short_name/theme_color reflètent
// l'identité du club en base. Remplace l'ancien fichier statique public/manifest.webmanifest.
// Icônes : téléversables par le club (cadrage §7.16) et servies depuis le disque `public` ;
// à défaut, repli sur le jeu versionné dans public/icons/ — une instance neuve reste installable.
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = ClubSettings::current();

        return response()->json([
            'name' => $settings->name,
            'short_name' => mb_substr($settings->name, 0, 12),
            'description' => "Planning d'entraînement du club",
            // `id` est l'identité STABLE de l'app installée. Une fois livré, il ne doit plus jamais
            // changer : le modifier ferait apparaître une seconde application chez ceux qui ont déjà
            // installé la première. Il vaut le start_url historique.
            'id' => '/',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Ouvrir les liens du domaine dans l'app installée plutôt que dans un onglet, et
            // réutiliser la fenêtre déjà ouverte — utile pour les liens d'email (invitation, lien
            // magique, notification).
            //
            // ATTENTION : Chromium uniquement. Safari n'implémente NI handle_links NI launch_handler.
            // Sur iOS, une PWA installée a un pot de cookies distinct de Safari : un lien cliqué dans
            // Mail ouvre la session dans Safari et laisse la PWA déconnectée, et rien dans ce fichier
            // n'y change quoi que ce soit. C'est le code à usage unique qui répond à ce cas.
            'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
            'handle_links' => 'preferred',
            'background_color' => '#ffffff',
            'theme_color' => $settings->primary_color ?: ClubPalette::DEFAULTS['primary_color'],
            'lang' => 'fr',
            'icons' => [
                [
                    'src' => $settings->pwaIconUrl('icon_192'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => $settings->pwaIconUrl('icon_512'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ])
            ->header('Content-Type', 'application/manifest+json')
            // Le <link rel="manifest"> est dans head-meta, donc sur TOUTES les pages : sans cache,
            // chaque récupération paie un démarrage complet du framework et une requête SQL sur le
            // mutualisé, pour un JSON qui ne bouge qu'à l'édition des paramètres du club.
            //
            // Une heure, et non plus un jour : depuis que les icônes PWA sont téléversables
            // (§7.16), un admin qui remplace les siennes doit pouvoir CONSTATER le changement.
            // Vingt-quatre heures d'écart entre l'upload et l'effet se lisent comme une panne et
            // provoquent des re-téléversements en boucle. Le coût reste négligeable : le manifest
            // n'est récupéré qu'à l'installation ou au rafraîchissement de la PWA, pas à chaque
            // page. (L'URL des icônes porte un segment aléatoire : les FICHIERS, eux, restent
            // cachables indéfiniment.)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
