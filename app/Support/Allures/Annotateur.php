<?php

namespace App\Support\Allures;

use App\Models\AllureZone;
use App\Models\Session;
use App\Models\User;
use App\Support\Markup;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Zones d'allure reconnues dans les consignes de séance (#113).
 *
 * Le coach écrit comme d'habitude (« 4x (2' Z4 / 2' Z3) ») : rien ne change dans l'éditeur ni
 * dans le Markdown stocké. À l'affichage, chaque code de zone du catalogue est complété par la
 * fourchette d'allure de la personne qui lit : « Z4 (4:41–4:56 /km) ».
 *
 * - Le motif vient du catalogue (pas de `Z\d` en dur) : un club peut coder I1–I5, EF, AS10…
 * - Travail sur le HTML DÉJÀ sanitisé, dans les nœuds texte seulement, jamais dans un lien.
 * - « − » et « - » valent l'un pour l'autre ; un tiret suivi d'un code est une plage, annotée
 *   d'une seule fourchette qui couvre les deux zones : « Z3-Z4 (4:41–5:14 /km) ».
 * - Repli naturel sur le texte brut : pas de VMA, discipline sans référentiel, code inconnu.
 * - Web uniquement : email et push gardent le texte brut.
 */
final class Annotateur
{
    /**
     * Consigne d'une séance rendue pour un lecteur : HTML annoté, et `invite` vrai quand une zone
     * a été reconnue mais que le lecteur n'a pas de VMA (afficher l'invitation à la renseigner).
     *
     * @return array{html: HtmlString, invite: bool}
     */
    public static function pourSeance(Session $session, ?User $lecteur): array
    {
        $html = (string) Markup::render($session->content_markdown);
        $referentiel = $session->discipline?->referentielEnum();
        if ($referentiel === null || $html === '') {
            return ['html' => new HtmlString($html), 'invite' => false];
        }

        $zones = AllureZone::activeFor($referentiel);
        $vma = $lecteur?->referenceValue($referentiel)?->value;
        [$annote, $reconnu] = self::annoter($html, $zones, $vma);

        return ['html' => new HtmlString($annote), 'invite' => $reconnu && $vma === null];
    }

    /**
     * @param  Collection<int, AllureZone>  $zones
     * @return array{string, bool} [HTML, au moins une zone reconnue]
     */
    public static function annoter(string $html, Collection $zones, ?float $vma): array
    {
        // Zone par code ou alias normalisé (signe moins unifié) : « SV2 » vaut sa zone.
        $parCode = $zones->flatMap(fn (AllureZone $z) => collect([$z->code, ...$z->aliasList()])
            ->mapWithKeys(fn (string $c) => [self::normaliser($c) => $z]));
        $motif = self::motif($parCode->keys());
        if ($motif === null || trim($html) === '') {
            return [$html, false];
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $racine = $doc->getElementsByTagName('div')->item(0);
        if ($racine === null) {
            return [$html, false];
        }

        $reconnu = false;
        foreach (self::noeudsTexte($racine) as $texte) {
            $morceaux = preg_split($motif, $texte->data, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($morceaux === false || count($morceaux) === 1) {
                continue;
            }
            $reconnu = true;
            if ($vma === null) {
                continue;
            }
            $fragment = $doc->createDocumentFragment();
            $n = count($morceaux);
            for ($i = 0; $i < $n; $i++) {
                $morceau = $morceaux[$i];
                // Les indices impairs sont les codes capturés.
                $zone = $i % 2 === 1 ? $parCode->get(self::normaliser($morceau)) : null;
                // Plage « Z3-Z4 » : code, tiret seul, code — une fourchette pour l'ensemble.
                $fin = $zone !== null && $i + 2 < $n && in_array($morceaux[$i + 1], ['-', '−'], true)
                    ? $parCode->get(self::normaliser($morceaux[$i + 2])) : null;
                if ($fin !== null) {
                    $morceau .= $morceaux[$i + 1].$morceaux[$i + 2];
                    $i += 2;
                }
                if ($morceau !== '') {
                    $fragment->appendChild($doc->createTextNode($morceau));
                }
                if ($zone !== null) {
                    $span = $doc->createElement('span');
                    $span->setAttribute('class', 'zone-allure');
                    $span->appendChild($doc->createTextNode(' ('.self::fourchette($zone, $vma, $fin).' /km)'));
                    $fragment->appendChild($span);
                }
            }
            $texte->parentNode?->replaceChild($fragment, $texte);
        }

        if (! $reconnu || $vma === null) {
            return [$html, $reconnu];
        }

        $out = '';
        foreach (iterator_to_array($racine->childNodes) as $enfant) {
            $out .= $doc->saveHTML($enfant);
        }

        return [$out, true];
    }

    /**
     * « 4:41–4:56 » : allure au % haut (rapide) puis au % bas (lent). Avec une seconde zone (plage),
     * la fourchette couvre les deux, dans quelque ordre qu'elles soient écrites.
     */
    public static function fourchette(AllureZone $zone, float $vma, ?AllureZone $jusqua = null): string
    {
        $haut = max($zone->pct_max, $jusqua->pct_max ?? $zone->pct_max);
        $bas = min($zone->pct_min, $jusqua->pct_min ?? $zone->pct_min);

        return Calculateur::formatAllure(Calculateur::allure($vma, $haut))
            .'–'.Calculateur::formatAllure(Calculateur::allure($vma, $bas));
    }

    /**
     * Alternance des codes et alias, les plus longs d'abord (« Z4+ » avant « Z4 »), en limites de mot.
     * Après un code : ni lettre, ni chiffre, ni « + », ni signe moins isolé (« Z3− » n'est pas
     * « Z3 » suivi d'un moins) ; un tiret suivi d'un caractère alphanumérique reste une plage.
     *
     * @param  Collection<int, string>  $codes  codes et alias
     */
    private static function motif(Collection $codes): ?string
    {
        $codes = $codes->filter()->unique()->sortByDesc(fn ($c) => mb_strlen($c))->values();
        if ($codes->isEmpty()) {
            return null;
        }
        // Codes déjà normalisés : tout signe moins s'écrit « - », et accepte les deux graphies.
        $alternance = $codes->map(fn ($c) => str_replace('\\-', '[−-]', preg_quote(self::normaliser($c), '/')))->implode('|');

        return '/(?<![\p{L}\p{N}_])('.$alternance.')(?![\p{L}\p{N}_+])(?![−-](?![\p{L}\p{N}]))/u';
    }

    private static function normaliser(string $code): string
    {
        return str_replace('−', '-', $code);
    }

    /** @return list<DOMText> nœuds texte hors liens. */
    private static function noeudsTexte(DOMNode $noeud): array
    {
        $out = [];
        foreach (iterator_to_array($noeud->childNodes) as $enfant) {
            if ($enfant instanceof DOMText) {
                $out[] = $enfant;
            } elseif ($enfant instanceof DOMElement && strtolower($enfant->tagName) !== 'a') {
                array_push($out, ...self::noeudsTexte($enfant));
            }
        }

        return $out;
    }
}
