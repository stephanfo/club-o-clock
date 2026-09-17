<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Services\CalendarFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flux d'abonnement agenda (#39, PRD §4.21.2). Route publique : l'agenda de l'adhérent la relit sans
 * session, le jeton de l'URL est la seule preuve.
 */
class CalendarFeedController extends Controller
{
    public function show(Request $request, string $token, CalendarFeedService $feeds): Response
    {
        $feed = CalendarFeed::query()->with('user')->where('token', $token)->first();

        abort_if($feed === null, 404);
        abort_if($feed->isRevoked(), 410);
        abort_if($feeds->isClosedFor($feed->user), 403);

        // Un robot d'agenda repasse souvent : une écriture par heure suffit à savoir si l'adresse sert.
        // Requête directe pour ne pas toucher updated_at, qui entre dans l'ETag.
        if ($feed->last_used_at === null || $feed->last_used_at->lt(Carbon::now()->subHour())) {
            CalendarFeed::query()->whereKey($feed->id)->toBase()->update(['last_used_at' => Carbon::now()]);
        }

        $entrees = $feeds->entries($feed);
        $etag = $feeds->etag($feed, $entrees);
        $entetes = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $entetes);
        }

        return response($feeds->render($feed, $entrees), 200, $entetes + [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
        ]);
    }
}
