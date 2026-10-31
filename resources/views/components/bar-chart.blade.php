{{-- Graphique en barres (PRD §4.16.1, cadrage « Graphiques ») — SVG rendu serveur, sans librairie.

     Reçoit : $points = [['label' => ?string, 'value' => int, 'line' => ?int, 'muted' => bool], …]
       · label : graduation d'abscisse (null = pas de libellé sous cette barre) ;
       · value : hauteur de la barre (série principale) ;
       · line  : seconde série, tracée en ligne à points (facultative) ;
       · muted : barre atténuée (ex. semaine en cours, partielle).
     $legend = libellé de la série principale, $lineLegend = celui de la seconde (affichés si fournis).

     Même construction que le profil altimétrique (x-alt-profile) : le tracé est un SVG étiré
     (`preserveAspectRatio="none"`) et TOUT texte vit en HTML autour — graduations Y dans une gouttière,
     abscisses dans une réglette, valeurs au-dessus des barres —, sinon l'étirement déformerait les
     chiffres. Le PRD exige un axe gradué et des valeurs lisibles sans survol (téléphone) : les valeurs
     sont écrites tant que les barres restent assez larges pour les porter (≤ 16), au-delà l'axe suffit. --}}
@props(['points' => [], 'legend' => null, 'lineLegend' => null, 'label' => 'Graphique'])
@php
    $points = array_values($points);
    $n = count($points);
    $hasLine = collect($points)->contains(fn ($p) => ($p['line'] ?? null) !== null);

    // Graduations « rondes » : pas de 1, 2 ou 5 × 10ᵏ visant ~5 intervalles, sommet arrondi au pas
    // supérieur. 212 → pas 50, sommet 250 ; 38 → pas 10, sommet 40 ; 7 → pas 2, sommet 8.
    $max = max(1, (int) collect($points)->flatMap(fn ($p) => [$p['value'] ?? 0, $p['line'] ?? 0])->max());
    $rough = $max / 5;
    $mag = 10 ** floor(log10($rough > 0 ? $rough : 1));
    $norm = $rough / $mag;
    $step = max(1, (int) (($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10))) * $mag));
    $top = (int) (ceil($max / $step) * $step);
    $ticks = range(0, $top, $step);

    $pct = fn ($v) => round(((int) $v) / $top * 100, 3);
    $center = fn (int $i) => round(($i + 0.5) / max(1, $n) * 100, 3);
    $showValues = $n > 0 && $n <= 16;

    $line = $hasLine
        ? implode(' ', array_map(fn ($i) => ($i * 10 + 5).','.(100 - $pct($points[$i]['line'] ?? 0)), array_keys($points)))
        : '';
@endphp
@if ($n > 0)
    <figure {{ $attributes->merge(['class' => 'chart']) }} role="img" aria-label="{{ $label }}">
        @if ($legend || ($hasLine && $lineLegend))
            <figcaption class="chart-legend" aria-hidden="true">
                @if ($legend)<span><i class="chart-key chart-key-bar"></i>{{ $legend }}</span>@endif
                @if ($hasLine && $lineLegend)<span><i class="chart-key chart-key-line"></i>{{ $lineLegend }}</span>@endif
            </figcaption>
        @endif

        <div class="chart-frame" aria-hidden="true">
            {{-- Gouttière Y : hors SVG pour échapper à l'étirement. Même hauteur que la zone de tracé. --}}
            <div class="chart-axis">
                <div class="chart-axis-plot">
                    @foreach ($ticks as $t)
                        <span class="chart-tick" style="top:{{ 100 - $pct($t) }}%">{{ $t }}</span>
                    @endforeach
                </div>
                <div class="chart-axis-foot"></div>
            </div>

            <div class="chart-col">
                <div class="chart-plot">
                    <svg viewBox="0 0 {{ $n * 10 }} 100" preserveAspectRatio="none">
                        @foreach ($ticks as $t)
                            <line x1="0" y1="{{ 100 - $pct($t) }}" x2="{{ $n * 10 }}" y2="{{ 100 - $pct($t) }}"
                                  stroke="var(--hair)" stroke-width="1" @if ($t > 0) stroke-dasharray="3 4" @endif
                                  vector-effect="non-scaling-stroke" />
                        @endforeach
                        @foreach ($points as $i => $p)
                            @php $h = $pct($p['value'] ?? 0); @endphp
                            <rect x="{{ $i * 10 + 1.5 }}" y="{{ 100 - $h }}" width="7" height="{{ $h }}"
                                  fill="var(--brand)" @if (! empty($p['muted'])) fill-opacity="0.4" @endif />
                        @endforeach
                        @if ($hasLine)
                            <polyline points="{{ $line }}" fill="none" stroke="var(--accent)" stroke-width="2"
                                      stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        @endif
                    </svg>

                    {{-- Points de la ligne et valeurs : en HTML, positionnés en %, pour rester ronds et lisibles. --}}
                    @if ($hasLine)
                        @foreach ($points as $i => $p)
                            <span class="chart-dot" style="left:{{ $center($i) }}%;top:{{ 100 - $pct($p['line'] ?? 0) }}%"></span>
                        @endforeach
                    @endif
                    @if ($showValues)
                        @foreach ($points as $i => $p)
                            <span class="chart-val" style="left:{{ $center($i) }}%;bottom:{{ $pct($p['value'] ?? 0) }}%">{{ $p['value'] ?? 0 }}</span>
                        @endforeach
                    @endif
                </div>

                <div class="chart-xaxis">
                    @foreach ($points as $i => $p)
                        @if (($p['label'] ?? null) !== null)
                            <span @class(['chart-xtick', 'chart-xtick-first' => $center($i) < 6, 'chart-xtick-last' => $center($i) > 94])
                                  style="left:{{ $center($i) }}%">{{ $p['label'] }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </figure>
@endif
