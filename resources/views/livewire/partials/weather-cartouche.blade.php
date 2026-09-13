{{-- Cartouche météo prévisionnelle (§4.13.5) — porté de screen-parcours.jsx <WeatherCartouche>.
     États : full (données) · far (> J-16) · nogeo (lieu non géocodé) · pending (récupération).
     Reçoit : $weatherState, $weather (array|null, agrégé sur la durée de la séance — #55). Source Open-Meteo mentionnée en pied (obligatoire). --}}
@if ($weatherState === 'nogeo')
    <div class="card card-pad flex ac g10" style="color:var(--fg-muted)">
        <x-icon name="map-pin" :size="18" style="flex:0 0 auto" />
        <div style="font-size:13px;line-height:1.45">Météo indisponible — le lieu n'est pas géocodé. Renseigne les coordonnées du lieu pour activer la prévision.</div>
    </div>
@elseif ($weatherState === 'far')
    <div class="card card-pad flex ac g10">
        <x-icon name="clock" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
        <div class="meta" style="font-size:13px;line-height:1.45">Météo disponible à l'approche — la prévision s'affichera <b>16 jours avant</b> la séance.</div>
    </div>
@elseif ($weatherState === 'pending')
    <div class="card card-pad flex ac g10">
        <x-icon name="clock" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
        <div class="meta" style="font-size:13px;line-height:1.45">Prévision météo en cours de récupération — reviens dans un instant.</div>
    </div>
@elseif ($weatherState === 'full' && $weather)
    @php
        $code = $weather['code'] ?? null;
        $tDeb = $weather['tempStart'] ?? null;
        $tFin = $weather['tempEnd'] ?? null;
        $vMin = $weather['windMin'] ?? null;
        $vMax = $weather['windMax'] ?? null;
        // Règles de PRÉSENTATION (#55) — le service ne rend que du brut. Le seuil de 2 °C évite
        // qu'un simple arrondi fasse apparaître une fausse évolution sur une séance courte ;
        // le vent, lui, se compare une fois arrondi, puisque c'est ainsi qu'il s'affiche.
        $plageTemp = $tDeb !== null && $tFin !== null && abs($tFin - $tDeb) >= 2;
        $plageVent = $vMin !== null && $vMax !== null && round($vMin) != round($vMax);
        $hDeb = $weather['hourStart'] ?? null;
        $hFin = $weather['hourEnd'] ?? null;
    @endphp
    <div class="card card-pad wx">
        <div class="wx-top">
            <x-icon :name="\App\Support\Weather::icon($code)" :size="46" class="wx-ic" />
            {{-- Le gros chiffre garde la température de DÉPART : c'est ce qu'on lit en premier, et
                 une plage y déborderait (38 px). L'évolution va dans la cellule « Temp. ». --}}
            <div class="wx-temp">{{ $tDeb !== null ? round($tDeb).'°' : '—' }}</div>
            <div class="f1">
                <div style="font-weight:700;font-size:15px">{{ \App\Support\Weather::label($code) }}</div>
                {{-- Annonce la plage horaire couverte : « Prévision · J-16 » désignait la fenêtre de
                     disponibilité, pas la fraîcheur, et devenait trompeur dès qu'on affiche des
                     fourchettes. --}}
                <div class="meta" style="font-size:12.5px">
                    @if ($hDeb === null)
                        Prévision
                    @elseif ($hFin === null || $hFin === $hDeb)
                        Prévision {{ sprintf('%02dh', $hDeb) }}
                    @else
                        Prévision {{ sprintf('%02dh', $hDeb) }}–{{ sprintf('%02dh', $hFin) }}
                    @endif
                </div>
            </div>
        </div>
        <div class="wx-grid">
            <div class="wx-cell">
                <div class="eyebrow"><x-icon name="droplet" :size="12" style="color:var(--info)" /> Précip.</div>
                <div class="v">{{ $weather['precipProb'] !== null ? round($weather['precipProb']).' %' : '—' }}</div>
                <div class="meta" style="font-size:11px">{{ $weather['precipMm'] !== null ? $weather['precipMm'].' mm' : '' }}</div>
            </div>
            <div class="wx-cell">
                <div class="eyebrow"><x-icon name="wind" :size="12" style="color:var(--fg-soft)" /> Vent</div>
                <div class="v">{{ $vMax === null ? '—' : ($plageVent ? round($vMin).'–'.round($vMax) : round($vMax)) }}<span style="font-size:12px;font-weight:600"> km/h</span></div>
                <div class="meta flex ac g4" style="font-size:11px">
                    <x-icon name="navigation" :size="11" class="wx-arrow" style="transform:rotate({{ ($weather['windDeg'] ?? 0) + 180 }}deg)" /> {{ \App\Support\Weather::direction($weather['windDeg'] ?? null) }}
                </div>
            </div>
            <div class="wx-cell">
                <div class="eyebrow"><x-icon name="gauge" :size="12" style="color:var(--fg-soft)" /> Temp.</div>
                {{-- La flèche reste soudée à la valeur d'arrivée : dans une colonne de grille 1fr,
                     la plage passe à la ligne dès 390 px, et une flèche orpheline en fin de
                     première ligne se lit mal. --}}
                <div class="v">@if ($tDeb === null)—@elseif ($plageTemp){{ round($tDeb) }}° <span style="white-space:nowrap">→ {{ round($tFin) }}°</span>@else{{ round($tDeb) }}°@endif</div>
            </div>
        </div>
        <div class="wx-source"><x-icon name="cloud" :size="12" /> Source : Open-Meteo · CC BY 4.0</div>
    </div>
@endif
