<?php

namespace App\Http\Controllers;

use App\Models\ClubSettings;
use Illuminate\View\View;

// Page publique mentions légales + politique de confidentialité (plan open source OS3).
//
// Les faits techniques (données traitées, flux sortants) sont portés par la vue : ils décrivent le
// CODE, identique pour toutes les instances. Ce qui identifie l'exploitant (éditeur, hébergeur,
// contacts) vient de ClubSettings et se saisit en admin — sans quoi un club ne pourrait renseigner
// ses mentions qu'en éditant le code source (revue open source, constat n°11).
class LegalController extends Controller
{
    public function __invoke(): View
    {
        return view('legal', ['settings' => ClubSettings::current()]);
    }
}
