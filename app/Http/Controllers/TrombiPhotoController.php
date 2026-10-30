<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Services\TrombiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

// Photo du trombinoscope (PRD §4.23.2, cadrage §7.18) : jamais d'URL publique, chaque affichage
// repasse par TrombiPolicy. `private, no-cache` + ETag : le navigateur revalide à chaque fois (304
// bon marché), si bien qu'un retrait d'accord ou un départ de saison prend effet au chargement
// suivant. Le service worker exclut cette route : rien ne survit dans un cache hors ligne.
class TrombiPhotoController extends Controller
{
    public function show(Request $request, User $user, int $size, TrombiPolicy $policy): Response
    {
        $user->load('unrevokedConsents');
        $path = TrombiService::photoFile($user, $size);

        // 404 et non 403 : une photo masquée ne doit pas se distinguer d'une photo absente.
        abort_unless($path !== null && $policy->photo($request->user(), $user), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        $etag = '"'.md5($path).'"';
        $headers = [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-cache',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return response(Storage::disk('local')->get($path), 200, $headers);
    }
}
