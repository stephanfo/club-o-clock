<?php

namespace App\Http\Controllers;

use App\Models\Session;
use App\Support\Ics;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

// Ajout ponctuel d'une séance à l'agenda perso (#39, PRD §4.21). Le fichier est une copie figée :
// ni un report ni une annulation ne la rattraperont — c'est le rôle de l'abonnement.
class SessionIcsController extends Controller
{
    public function download(Session $session): Response
    {
        Gate::authorize('view', $session);

        $session->load('location');
        $ics = Ics::calendar([Ics::event($session, auth()->user())]);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Ics::filename($session).'"',
        ]);
    }
}
