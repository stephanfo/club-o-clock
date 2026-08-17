{{-- Cartouche météo prévisionnelle (§4.13.5) — porté de screen-parcours.jsx <WeatherCartouche>.
     États : full (données) · far (> J-16) · nogeo (lieu non géocodé) · pending (récupération).
     Reçoit : $weatherState, $weather (array|null). Source Open-Meteo mentionnée en pied (obligatoire). --}}
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
    @php $code = $weather['code'] ?? null; @endphp
    <div class="card card-pad wx">
        <div class="wx-top">
            <x-icon :name="\App\Support\Weather::icon($code)" :size="46" class="wx-ic" />
            <div class="wx-temp">{{ $weather['temp'] !== null ? round($weather['temp']).'°' : '—' }}</div>
            <div class="f1">
                <div style="font-weight:700;font-size:15px">{{ \App\Support\Weather::label($code) }}</div>
                <div class="meta" style="font-size:12.5px">Prévision · J-16</div>
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
                <div class="v">{{ $weather['wind'] !== null ? round($weather['wind']) : '—' }}<span style="font-size:12px;font-weight:600"> km/h</span></div>
                <div class="meta flex ac g4" style="font-size:11px">
                    <x-icon name="navigation" :size="11" class="wx-arrow" style="transform:rotate({{ ($weather['windDeg'] ?? 0) + 180 }}deg)" /> {{ \App\Support\Weather::direction($weather['windDeg'] ?? null) }}
                </div>
            </div>
            <div class="wx-cell">
                <div class="eyebrow"><x-icon name="gauge" :size="12" style="color:var(--fg-soft)" /> Temp.</div>
                <div class="v">{{ $weather['temp'] !== null ? round($weather['temp']).'°' : '—' }}</div>
            </div>
        </div>
        <div class="wx-source"><x-icon name="cloud" :size="12" /> Source : Open-Meteo · CC BY 4.0</div>
    </div>
@endif
