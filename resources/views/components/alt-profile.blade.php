{{-- Profil altimétrique — porté de screen-parcours.jsx <AltProfile>.
     Le proto dessinait une polyline en dur ; ici les points viennent de `elevation_profile`
     (extrait client, borné par GpxStats::sanitizeElevationProfile), rendus SERVEUR : SVG statique,
     ni JS ni fetch. Classe .alt-profile déjà présente dans club-app.css:679.

     Reçoit : $profile = [[distKm, altM], …] (≥ 2 paires) ou null.

     Axe Y (ajout 2026-08-02) : trois graduations chiffrées dans une gouttière à GAUCHE, hors du SVG.
     Le tracé garde `preserveAspectRatio="none"` — il doit s'étirer sur toute la largeur — ce qui
     déformerait tout texte placé à l'intérieur. D'où la séparation : gouttière en HTML (typographie
     intacte, tokens du design system), tracé en SVG étiré. Les lignes d'horizon, elles, vivent dans
     le SVG : une graduation doit rester alignée sur sa ligne quelle que soit la largeur. --}}
@props(['profile' => null, 'label' => 'Profil altimétrique du parcours', 'distanceKm' => null])
@php
    $pts = is_array($profile) ? array_values(array_filter($profile, fn ($p) => is_array($p) && count($p) >= 2)) : [];

    if (count($pts) >= 2) {
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);

        // Une trace parfaitement plate (ou d'emprise nulle) diviserait par zéro : on l'aplatit
        // au milieu du cadre plutôt que d'échouer — c'est la lecture honnête d'un profil sans relief.
        $spanX = $maxX - $minX;
        $spanY = $maxY - $minY;

        // Marge verticale de 8 px en haut et en bas : sans elle, le sommet et le creux se collent
        // au bord du cadre et la ligne paraît coupée.
        $y = fn (float $alt) => $spanY > 0 ? round(84 - ($alt - $minY) / $spanY * 76, 1) : 46.0;

        $coords = [];
        foreach ($pts as [$dist, $alt]) {
            $x = $spanX > 0 ? ($dist - $minX) / $spanX * 400 : 200;
            $coords[] = round($x, 1).','.$y((float) $alt);
        }

        $line = implode(' ', $coords);
        // Aire fermée : on redescend au bas du cadre à droite puis à gauche.
        $area = '0,92 '.$line.' 400,92';

        // Graduations : bas, milieu, haut. Sur une trace plate les trois valeurs se confondent —
        // on n'affiche alors que l'altitude unique, au milieu, plutôt que trois fois le même chiffre.
        $mid = ($minY + $maxY) / 2;
        $ticks = $spanY > 0
            ? [[$maxY, $y($maxY)], [$mid, $y($mid)], [$minY, $y($minY)]]
            : [[$minY, 46.0]];

        // ── Axe X (2026-08-02) : graduations à valeurs RONDES (0, 5, 10… km) plutôt qu'à
        // positions régulières. Un « 11,064 km » pile au milieu se lit moins bien qu'un « 10 km »
        // légèrement décalé : on lit une distance, pas une fraction de graphe.
        //
        // Le pas s'adapte à la longueur, sinon un 3 km n'aurait qu'une graduation et un 80 km en
        // aurait seize qui se chevauchent. Seuils calés sur le corpus du club (sorties vélo 20-90 km,
        // CAP 5-20 km) et sur la place disponible : ~250 px de large sur mobile, soit 5 libellés max.
        // Le pas se calcule sur la LONGUEUR RÉELLE du parcours, pas sur $maxX. Le profil échantillonne
        // à pas constant (total/120) : son dernier point tombe systématiquement avant la fin, d'un pas
        // soit ~0,8 %. Sur un parcours juste au-dessus d'un seuil (30,06 km), $maxX repasse dessous
        // (29,81) et le profil choisit un pas de 5 là où la carte, qui travaille sur le total brut,
        // choisit 10 — les deux vues n'affichent alors plus les mêmes kilomètres (revue 2026-08-02).
        $lengthKm = $distanceKm !== null ? (float) $distanceKm : $maxX;

        $xTicks = [];
        if ($spanX > 0) {
            $stepKm = match (true) {
                $lengthKm <= 2 => 0.5,
                $lengthKm <= 6 => 1,
                $lengthKm <= 12 => 2,
                $lengthKm <= 30 => 5,
                $lengthKm <= 60 => 10,
                $lengthKm <= 120 => 20,
                default => 50,
            };

            // La dernière graduation ronde tombe rarement sur la fin du parcours : c'est assumé
            // (c'est le prix des valeurs rondes). On borne à $maxX pour ne pas déborder du cadre.
            for ($d = ceil($minX / $stepKm) * $stepKm; $d <= $maxX + 1e-9; $d += $stepKm) {
                $xTicks[] = [$d, round(($d - $minX) / $spanX * 100, 3)];
            }

            // Une graduation solitaire (« 0 » seul sur un parcours de 800 m) n'informe de rien et
            // fait payer 17 px de hauteur pour rien : pas d'axe du tout dans ce cas.
            if (count($xTicks) < 2) {
                $xTicks = [];
            }
        }
    }
@endphp
@if (count($pts) >= 2)
    {{-- .alt-xruled n'est posée que si la réglette existe : sans elle, le cadre garde ses 92 px
         d'origine au lieu de payer 22 px de bande vide (revue 2026-08-02). --}}
    <div {{ $attributes->merge(['class' => 'alt-profile alt-graded'.($xTicks !== [] ? ' alt-xruled' : '')]) }}
         role="img" aria-label="{{ $label }}">
        {{-- Gouttière : hors SVG pour échapper à l'étirement horizontal. Le `top` en % se rapporte à
             .alt-axis-plot, qui a exactement la hauteur du SVG (et NON celle du cadre, réglette X
             comprise) — sans quoi chaque altitude glisserait sous sa ligne d'horizon. --}}
        <div class="alt-axis" aria-hidden="true">
            <div class="alt-axis-plot">
                @foreach ($ticks as [$value, $pos])
                    <span class="alt-tick" style="top:{{ round($pos / 92 * 100, 2) }}%">{{ round($value) }} m</span>
                @endforeach
            </div>
            @if ($xTicks !== [])
                {{-- Cale muette : reproduit la réglette pour que la zone chiffrée s'arrête à la même
                     ligne que le tracé. Le filet la traverse, prolongeant celui de la réglette. --}}
                <div class="alt-axis-foot"></div>
            @endif
        </div>

        {{-- Colonne de tracé : le SVG occupe la place restante, la réglette X est ancrée en bas.
             Les deux partagent la même largeur, donc une graduation tombe pile sous son abscisse. --}}
        <div class="alt-plot">
            <svg viewBox="0 0 400 92" preserveAspectRatio="none" aria-hidden="true">
                {{-- Lignes d'horizon : dans le SVG pour rester alignées sur leur graduation. --}}
                @foreach ($ticks as [$value, $pos])
                    <line x1="0" y1="{{ $pos }}" x2="400" y2="{{ $pos }}" stroke="var(--hair)"
                          stroke-width="1" stroke-dasharray="3 4" vector-effect="non-scaling-stroke" />
                @endforeach
                <polygon points="{{ $area }}" fill="var(--info)" fill-opacity="0.12" />
                <polyline points="{{ $line }}" fill="none" stroke="var(--info)" stroke-width="2.5"
                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </svg>

            {{-- Réglette X : DANS le cadre (2026-08-02), sous le tracé, séparée par un filet. Hors du
                 SVG malgré tout, comme la gouttière Y : `preserveAspectRatio="none"` déformerait le
                 texte. Le cadre est agrandi d'autant pour que le tracé garde sa hauteur utile. --}}
            @if ($xTicks !== [])
                <div class="alt-xaxis" aria-hidden="true">
                    @foreach ($xTicks as $i => [$value, $pos])
                        {{-- Le recadrage des bords dépend de la POSITION, pas de l'index : la dernière
                             graduation ronde tombe souvent vers 90 %, où un libellé centré tient
                             encore — l'aligner à droite le décalerait alors visiblement de sa propre
                             abscisse (13 px mesurés à 90,4 %). Seules les graduations réellement au
                             bord sont recadrées (revue 2026-08-02).
                             L'unité est accolée à la dernière graduation plutôt que posée à droite du
                             cadre : à droite, elle chevaucherait ce libellé. --}}
                        <span @class([
                                  'alt-xtick',
                                  'alt-xtick-first' => $pos < 4,
                                  'alt-xtick-last' => $pos > 96,
                              ])
                              style="left:{{ $pos }}%">{{ $value + 0 }}@if ($i === count($xTicks) - 1)&nbsp;km @endif</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
