{{-- Table club des allures cibles (#114) — % de VMA tenable par niveau × distance.
     Admin, assumé desktop. Structure alignée sur le gestionnaire de catalogues.
     La ligne Riegel est calculée (lecture seule) : ses % sont montrés pour une plage de VMA. --}}
@php
    [$vmaLent, $vmaRapide] = \App\Livewire\Admin\AllureLevels::RIEGEL_VMA_EXEMPLE;
    $pct = fn (float $v) => str_replace('.', ',', (string) round($v));
@endphp
<div class="form-screen">
    <x-flash-float />
    <div class="dk-topbar">
        <a href="{{ route('admin.settings') }}" class="btn btn-ghost btn-sm" wire:navigate>
            <x-icon name="chevron-left" :size="15" /> Paramètres
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">Allures cibles</div>
            <div class="meta">% de VMA tenable par niveau et par distance · {{ count($rows) }} niveau(x)</div>
        </div>
        <button type="button" wire:click="addRow" class="btn btn-ghost btn-sm">
            <x-icon name="plus" :size="15" /> Ajouter un niveau
        </button>
        <button type="button" wire:click="save" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="save">Enregistrer</button>
    </div>

    <div class="dk-body">
        {{-- Plus large que les catalogues : les 4 distances d'un niveau tiennent sur une ligne. --}}
        <div class="cat-wrap" style="max-width:960px">
            <x-banner kind="info"><div>
                Sert à estimer la VMA depuis un résultat de course et à projeter les temps, sur l'écran
                « Allures course » des membres. Le <b>modèle de Riegel</b> (exposant 1,06) est calculé :
                ses % dépendent de la VMA, la ligne les montre pour une VMA de {{ (int) $vmaLent }} à
                {{ (int) $vmaRapide }} km/h. Ajoutez vos niveaux, ordonnez-les (le premier proposé est le choix
                par défaut) et décochez « Proposé » pour en masquer un aux membres, Riegel compris.
                Laisser le % maximum vide pour une borne ouverte (« 95+ »), ou le rendre égal au minimum
                pour une valeur unique.
            </div></x-banner>

            @if ($errors->any())
                <x-banner kind="warn"><div>{{ $errors->first() }}</div></x-banner>
            @endif

            {{-- Une carte par niveau : libellé et réglages en tête, distances en grille dessous —
                 des champs assez larges pour lire ce qu'on saisit. --}}
            @foreach ($rows as $i => $row)
                @php $isRiegel = ($row['model'] ?? null) === \App\Models\AllureLevel::MODEL_RIEGEL; @endphp
                <div class="card card-pad" wire:key="level-{{ $i }}-{{ $row['model'] ?? 'club' }}" @style(['opacity:.6' => ! ($row['active'] ?? true)])>
                    <div class="flex ac g10 wrap">
                        <div class="f1" style="min-width:200px">
                            @if ($isRiegel)
                                <div style="font-weight:700;font-size:16px">{{ $row['label'] }}</div>
                                <div class="meta" style="font-size:12px">Calculé · % tenus pour une VMA de {{ (int) $vmaLent }} → {{ (int) $vmaRapide }} km/h</div>
                            @else
                                <label class="field-label" for="lv-{{ $i }}">Niveau {{ $i + 1 }}</label>
                                <div class="ifield" style="max-width:320px"><input id="lv-{{ $i }}" class="ifield-input" type="text" wire:model.blur="rows.{{ $i }}.label" placeholder="Débutant"></div>
                            @endif
                        </div>
                        <div class="flex ac g8">
                            <span class="meta" style="font-size:12px">Proposé</span>
                            <x-toggle :on="$row['active'] ?? true" wire:click="toggleActive({{ $i }})" aria-label="Proposé aux membres : {{ $row['label'] ?: 'niveau '.($i + 1) }}" />
                        </div>
                        <div class="flex ac g4" style="min-width:68px;justify-content:flex-end">
                            @if ($i > 0)
                                <button type="button" class="iconbtn" wire:click="moveUp({{ $i }})" title="Monter" aria-label="Monter"><x-icon name="chevron-up" :size="16" /></button>
                            @endif
                            @unless ($isRiegel)
                                <button type="button" class="iconbtn" wire:click="removeRow({{ $i }})" title="Retirer" aria-label="Retirer"><x-icon name="trash" :size="16" /></button>
                            @endunless
                        </div>
                    </div>
                    <div class="niveau-grille">
                        @foreach ($distances as $key => $d)
                            <div>
                                <div class="eyebrow" style="margin-bottom:6px">{{ $d[0] }} <span style="text-transform:none;letter-spacing:0">· % VMA</span></div>
                                @if ($isRiegel)
                                    <div class="meta" style="padding:10px 0">{{ $pct($riegel[$key][0]) }} – {{ $pct($riegel[$key][1]) }} %</div>
                                @else
                                    <div class="flex ac g6">
                                        <div class="ifield f1" style="min-width:0"><input class="ifield-input" type="text" inputmode="decimal" wire:model.blur="rows.{{ $i }}.targets.{{ $key }}.min" aria-label="% minimum {{ $d[0] }}" placeholder="min"></div>
                                        <span class="meta">–</span>
                                        <div class="ifield f1" style="min-width:0"><input class="ifield-input" type="text" inputmode="decimal" wire:model.blur="rows.{{ $i }}.targets.{{ $key }}.max" aria-label="% maximum {{ $d[0] }}" placeholder="max ou +"></div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
