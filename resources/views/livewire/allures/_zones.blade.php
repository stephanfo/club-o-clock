{{-- Allures (#114) — curseur d'intensité et tableau des zones, affichés dès qu'une VMA est connue. --}}
@use('App\Support\Allures\Calculateur', 'C')
@if ($vmaValue)
    @php
        // Bande de zones sur la plage du curseur (50–105 % VMA) ; les trous restent neutres.
        [$lo, $hi] = [50, 105];
        $bande = [];
        $cur = $lo;
        foreach ($zones as $z) {
            $a = max($z->pct_min, $lo);
            $b = min($z->pct_max, $hi);
            if ($a > $cur) {
                $bande[] = [($a - $cur) / ($hi - $lo) * 100, null, ''];
            }
            if ($b > $a) {
                $bande[] = [($b - $a) / ($hi - $lo) * 100, $couleurs[$z->id], $z->code];
                $cur = $b;
            }
        }
        $grille = $referentiel->gridDistances();
        // Distances officielles (1 km, 5 km, 10 km, semi, marathon) : colonnes mises en avant.
        $officielles = $referentiel->officialGridDistances();
    @endphp
    {{-- ── Curseur d'intensité : formule triviale recalculée côté navigateur. Les valeurs sont
         au-dessus du curseur, pour que le doigt qui glisse ne les cache pas. ── --}}
    <div class="card card-pad" wire:key="al-curseur"
         x-data="{ pct: 85, vma: {{ $vmaValue }}, zones: @js($zones->map(fn ($z) => [$z->badge(), $z->label, $z->pct_min, $z->pct_max, $couleurs[$z->id]])->values()),
            fmt(s) { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = String(s % 60).padStart(2, '0'); return h ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss; },
            t(d) { return d * 3.6 / (this.vma * this.pct / 100); },
            get zone() { return this.zones.find(z => this.pct >= z[2] && this.pct <= z[3]) || null; } }">
        <div class="eyebrow">Mon allure selon l'intensité · VMA {{ C::formatVma($vmaValue) }} km/h</div>
        <div class="zone-readout">
            <div><span class="eyebrow">Allure</span><span><span class="num" x-text="fmt(t(1000))"></span><span class="unite">/km</span></span></div>
            <div><span class="eyebrow">Vitesse</span><span><span class="num" x-text="(vma * pct / 100).toFixed(1).replace('.', ',')"></span><span class="unite">km/h</span></span></div>
            <div><span class="eyebrow">200 m</span><span class="num" x-text="fmt(t(200))"></span></div>
            <div><span class="eyebrow">400 m</span><span class="num" x-text="fmt(t(400))"></span></div>
            <div><span class="eyebrow">800 m</span><span class="num" x-text="fmt(t(800))"></span></div>
            <div><span class="eyebrow">1000 m</span><span class="num" x-text="fmt(t(1000))"></span></div>
        </div>
        {{-- Bandeau pleine largeur, toujours présent : hauteur fixe, aucun saut de ligne en glissant. --}}
        <div class="chip zone-badge" :class="zone ? '' : 'zone-badge-hors'" :style="zone ? 'background:' + zone[4] : ''"
             x-text="zone ? zone[0] : 'Hors zone'" aria-live="polite"></div>
        <label class="field-label" for="al-pct-{{ $this->getId() }}" style="margin:12px 0 0">
            Intensité : <b x-text="pct + ' %'"></b> de VMA
        </label>
        <div class="zone-strip-wrap">
            <div class="zone-strip" aria-hidden="true">
                @foreach ($bande as [$w, $c, $code])
                    <span style="width:{{ $w }}%;{{ $c ? 'background:'.$c : '' }}" @if ($code) title="{{ $code }}" @endif></span>
                @endforeach
            </div>
            <div class="zone-marker" :style="'left:' + ((pct - {{ $lo }}) / {{ $hi - $lo }} * 100) + '%'"></div>
        </div>
        <input id="al-pct-{{ $this->getId() }}" type="range" class="zone-range" min="{{ $lo }}" max="{{ $hi }}" step="1" x-model.number="pct">
    </div>

    {{-- ── Mes allures par zone ── --}}
    <div class="card" style="overflow:hidden" wire:key="al-zones">
        <div style="padding:14px 16px 10px">
            <div class="eyebrow">Mes allures par zone</div>
        </div>
        @if ($zones->isEmpty())
            <div class="meta" style="padding:0 16px 16px">Aucune zone définie par le club.</div>
        @else
            {{-- Toutes les distances, de la piste au marathon : défilement horizontal, zone figée. --}}
            <div style="overflow-x:auto;border-top:1px solid var(--divider)">
                <table class="tbl tbl-figee">
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th class="r">Allure /km</th>
                            @foreach ($grille as $label => $m)
                                <th @class(['r', 'col-officielle' => in_array($label, $officielles, true)])>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($zones as $z)
                            <tr wire:key="zone-{{ $z->id }}">
                                <td>
                                    <div style="white-space:nowrap"><span class="zone-dot" style="background:{{ $couleurs[$z->id] }}"></span><b>{{ $z->codes() }}</b></div>
                                    <div class="meta" style="font-size:12px;margin-top:2px">{{ $z->label }}</div>
                                    <div class="meta" style="font-size:12px;white-space:nowrap">{{ $z->range() }}</div>
                                </td>
                                {{-- Fourchette sur deux lignes, le plus rapide en haut : colonnes plus étroites. --}}
                                <td class="r" style="font-weight:700"><span class="fourchette"><span>{{ C::formatAllure(C::allure($vmaValue, $z->pct_max)) }}</span><span>{{ C::formatAllure(C::allure($vmaValue, $z->pct_min)) }}</span></span></td>
                                @foreach ($grille as $label => $m)
                                    <td @class(['r', 'col-officielle' => in_array($label, $officielles, true)])><span class="fourchette"><span>{{ C::formatTemps(C::temps($m, $vmaValue, $z->pct_max)) }}</span><span>{{ C::formatTemps(C::temps($m, $vmaValue, $z->pct_min)) }}</span></span></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endif
