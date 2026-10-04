{{-- Allures course (#114) — VMA du profil, allures par zone, estimation depuis une course,
     projection de temps. Une seule colonne centrée, commune aux deux formats (comme Infos). --}}
@php
    use App\Support\Allures\Calculateur as C;
    $distances = $referentiel->distances();
    $model = $level && ! $level->isRiegel() ? 'Table du club — '.$level->label : 'Modèle de Riegel (exposant '.str_replace('.', ',', (string) C::RIEGEL_EXPOSANT).')';
    $range = fn (array $t) => abs($t[0] - $t[1]) < 1 ? C::formatTemps($t[0]) : C::formatTemps($t[0]).' – '.C::formatTemps($t[1]);
@endphp
<div class="form-screen">
    <x-flash-float />
    <x-topbar title="Allures course" :back="route('profil')" back-label="Retour profil" />
    <div class="dk-topbar">
        <a href="{{ route('profil') }}" class="btn btn-ghost btn-sm" onclick="return !window.clubBack?.()">
            <x-icon name="chevron-left" :size="15" /> Profil
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">Allures course</div>
            <div class="meta">Ta VMA et tes allures d'entraînement. Visible de toi seul·e.</div>
        </div>
    </div>

    <div class="dk-body">
        <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:14px">

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
                <div class="card" style="overflow:hidden">
                    <div style="padding:14px 16px 4px">
                        <div class="eyebrow">Mes allures par zone · VMA {{ C::formatVma($vmaValue) }} km/h</div>
                    </div>
                    @if ($zones->isEmpty())
                        <div class="meta" style="padding:8px 16px 16px">Aucune zone définie par le club.</div>
                    @else
                        <div style="overflow-x:auto">
                            <table class="tbl">
                                <thead><tr><th>Zone</th><th>Allure /km</th><th>400 m</th></tr></thead>
                                <tbody>
                                    @foreach ($zones as $z)
                                        <tr wire:key="zone-{{ $z->id }}">
                                            <td>
                                                <span class="chip chip-sm chip-line">{{ $z->code }}</span> <span class="meta">{{ $z->label }}</span>
                                                <div class="meta" style="font-size:12px;margin-top:2px">{{ $z->range() }} VMA</div>
                                            </td>
                                            <td style="font-weight:700;white-space:nowrap">{{ C::formatAllure(C::allure($vmaValue, $z->pct_max)) }} – {{ C::formatAllure(C::allure($vmaValue, $z->pct_min)) }}</td>
                                            <td style="white-space:nowrap">{{ C::formatTemps(C::temps(400, $vmaValue, $z->pct_max)) }} – {{ C::formatTemps(C::temps(400, $vmaValue, $z->pct_min)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Curseur de % libre : formule triviale recalculée côté navigateur. --}}
                    <div style="padding:14px 16px;border-top:1px solid var(--divider)"
                         x-data="{ pct: 85, vma: {{ $vmaValue }}, zones: @js($zones->map(fn ($z) => [$z->code, $z->pct_min, $z->pct_max])->values()),
                            fmt(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); },
                            get zone() { const z = this.zones.find(z => this.pct >= z[1] && this.pct <= z[2]); return z ? z[0] : ''; } }">
                        <div class="flex ac jb g8">
                            <label class="field-label" for="al-pct" style="margin:0">À <b x-text="pct"></b> % <span class="chip chip-sm chip-line" x-show="zone" x-text="zone"></span></label>
                            <span><b x-text="fmt(3600 / (vma * pct / 100))"></b> <span class="meta">/km · 400 m en</span> <b x-text="fmt(400 * 3.6 / (vma * pct / 100))"></b></span>
                        </div>
                        <input id="al-pct" type="range" min="50" max="105" step="1" x-model.number="pct" style="width:100%;accent-color:var(--brand)">
                    </div>
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
    </div>
</div>
