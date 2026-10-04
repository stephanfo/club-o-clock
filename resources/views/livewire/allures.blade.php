{{-- Allures course (#114) — VMA du profil, allures par zone, estimation depuis une course,
     projection de temps. Une seule colonne centrée, commune aux deux formats (comme Infos). --}}
@php
    use App\Support\Allures\Calculateur as C;
    $distances = $referentiel->distances();
    $model = $level && ! $level->isRiegel() ? 'Table du club — '.$level->label : 'Modèle de Riegel (exposant '.str_replace('.', ',', (string) C::RIEGEL_EXPOSANT).')';
    $range = fn (array $t) => abs($t[0] - $t[1]) < 1 ? C::formatTemps($t[0]) : C::formatTemps($t[0]).' – '.C::formatTemps($t[1]);
@endphp
{{-- Onglet du profil : pas de topbar propre. Flash rendu ici, ce composant se re-rend seul. --}}
<div style="display:flex;flex-direction:column;gap:14px">
    <x-flash-float />
    <div class="meta">Ta VMA et tes allures d'entraînement. Visible de toi seul·e.</div>

    {{-- Sans VMA renseignée, l'estimation passe en tête (ordre du DOM, pas seulement visuel). --}}
    @unless ($reference)
        @include('livewire.allures._estimation')
    @endunless

    {{-- ── Ma VMA ── --}}
    <div class="card card-pad">
        <div class="eyebrow" style="margin-bottom:8px">Ma VMA</div>
        @if ($reference)
            <div class="flex ac g8 wrap">
                <span class="num" style="font-size:32px">{{ C::formatVma($reference->value) }}</span>
                <span class="meta">km/h · {{ $reference->originLabel() }}</span>
            </div>
        @else
            <div class="meta">Pas encore renseignée. Estime-la depuis une course récente, ou saisis-la si tu la connais (test au club, en labo…).</div>
        @endif
        <div class="flex g8 wrap" style="align-items:flex-end;margin-top:12px">
            <div style="width:140px">
                <label class="field-label" for="al-vma">VMA (km/h)</label>
                <div class="ifield"><input id="al-vma" class="ifield-input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="vma" placeholder="13,5"></div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" wire:click="saveVma" wire:loading.attr="disabled" wire:target="saveVma">Enregistrer</button>
            @if ($reference)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="clearVma" wire:confirm="Effacer ta VMA ?" wire:loading.attr="disabled" wire:target="clearVma">Effacer</button>
            @endif
        </div>
        @if ($vmaInvalid)
            <div class="meta" style="color:var(--warning-text);margin-top:6px">VMA improbable : entre {{ (int) $referentiel->bounds()[0] }} et {{ (int) $referentiel->bounds()[1] }} km/h.</div>
        @endif
    </div>

    @if ($vmaValue)
        {{-- ── Mes allures par zone ── --}}
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
        @endphp
        <div class="card" style="overflow:hidden">
            <div style="padding:14px 16px 4px">
                <div class="eyebrow">Mes allures par zone · VMA {{ C::formatVma($vmaValue) }} km/h</div>
            </div>

            {{-- Curseur d'intensité : formule triviale recalculée côté navigateur. --}}
            <div style="padding:8px 16px 16px"
                 x-data="{ pct: 85, vma: {{ $vmaValue }}, zones: @js($zones->map(fn ($z) => [$z->code, $z->label, $z->pct_min, $z->pct_max, $couleurs[$z->id]])->values()),
                    fmt(s) { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = String(s % 60).padStart(2, '0'); return h ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss; },
                    t(d) { return d * 3.6 / (this.vma * this.pct / 100); },
                    get zone() { return this.zones.find(z => this.pct >= z[2] && this.pct <= z[3]) || null; } }">
                <label class="field-label" for="al-pct" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">
                    Intensité : <b x-text="pct + ' %'"></b> de VMA
                    <span class="chip chip-sm zone-badge" x-show="zone" :style="zone && 'background:' + zone[4]" x-text="zone && (zone[0] + ' · ' + zone[1])"></span>
                    <span class="chip chip-sm" x-show="!zone">hors zone</span>
                </label>
                <div class="zone-strip-wrap">
                    <div class="zone-strip" aria-hidden="true">
                        @foreach ($bande as [$w, $c, $code])
                            <span style="width:{{ $w }}%;{{ $c ? 'background:'.$c : '' }}" @if ($code) title="{{ $code }}" @endif></span>
                        @endforeach
                    </div>
                    <div class="zone-marker" :style="'left:' + ((pct - {{ $lo }}) / {{ $hi - $lo }} * 100) + '%'"></div>
                </div>
                <input id="al-pct" type="range" class="zone-range" min="{{ $lo }}" max="{{ $hi }}" step="1" x-model.number="pct">
                <div class="zone-readout">
                    <div><span class="eyebrow">Allure</span><span><span class="num" x-text="fmt(t(1000))"></span><span class="unite">/km</span></span></div>
                    <div><span class="eyebrow">Vitesse</span><span><span class="num" x-text="(vma * pct / 100).toFixed(1).replace('.', ',')"></span><span class="unite">km/h</span></span></div>
                    <div><span class="eyebrow">200 m</span><span class="num" x-text="fmt(t(200))"></span></div>
                    <div><span class="eyebrow">400 m</span><span class="num" x-text="fmt(t(400))"></span></div>
                    <div><span class="eyebrow">800 m</span><span class="num" x-text="fmt(t(800))"></span></div>
                    <div><span class="eyebrow">1000 m</span><span class="num" x-text="fmt(t(1000))"></span></div>
                </div>
            </div>

            @if ($zones->isEmpty())
                <div class="meta" style="padding:8px 16px 16px">Aucune zone définie par le club.</div>
            @else
                {{-- Toutes les distances, de la piste au marathon : défilement horizontal, zone figée. --}}
                <div style="overflow-x:auto;border-top:1px solid var(--divider)">
                    <table class="tbl tbl-figee">
                        <thead>
                            <tr>
                                <th>Zone</th>
                                <th class="r">Allure /km</th>
                                @foreach ($grille as $label => $m)
                                    <th class="r">{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zones as $z)
                                <tr wire:key="zone-{{ $z->id }}">
                                    <td>
                                        <span class="zone-dot" style="background:{{ $couleurs[$z->id] }}"></span><b>{{ $z->code }}</b>
                                        <div class="meta" style="font-size:12px;margin-top:2px">{{ $z->label }}</div>
                                        <div class="meta" style="font-size:12px;white-space:nowrap">{{ $z->range() }}</div>
                                    </td>
                                    <td class="r" style="font-weight:700">{{ C::formatAllure(C::allure($vmaValue, $z->pct_max)) }} – {{ C::formatAllure(C::allure($vmaValue, $z->pct_min)) }}</td>
                                    @foreach ($grille as $m)
                                        <td class="r">{{ C::formatTemps(C::temps($m, $vmaValue, $z->pct_max)) }} – {{ C::formatTemps(C::temps($m, $vmaValue, $z->pct_min)) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @endif

    @if ($reference)
        @include('livewire.allures._estimation')
    @endif

    @if ($vmaValue)
        {{-- ── Projection ── --}}
        <div class="card card-pad">
            <div class="eyebrow" style="margin-bottom:8px">Projection de temps de course</div>
            @foreach ($distances as $key => [$label])
                @isset($projection[$key])
                    <div class="flex ac jb" style="padding:8px 0;border-bottom:1px solid var(--divider)">
                        <span>{{ $label }}</span>
                        <span style="font-weight:700">{{ $range($projection[$key]) }}</span>
                    </div>
                @endisset
            @endforeach
            <div class="meta" style="font-size:12px;margin-top:10px">{{ $model }}.</div>
        </div>
    @endif
</div>
