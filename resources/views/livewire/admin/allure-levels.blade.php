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
        {{-- Plus large que les catalogues : 4 distances × 2 bornes, plus « Proposé » et actions. --}}
        <div class="cat-wrap" style="max-width:1120px">
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

            <div class="card" style="overflow-x:auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Niveau</th>
                            @foreach ($distances as [$label])
                                <th>{{ $label }} <span style="text-transform:none">(min – max %)</span></th>
                            @endforeach
                            <th>Proposé</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            @php $isRiegel = ($row['model'] ?? null) === \App\Models\AllureLevel::MODEL_RIEGEL; @endphp
                            <tr wire:key="level-{{ $i }}-{{ $row['model'] ?? 'club' }}" @style(['opacity:.6' => ! ($row['active'] ?? true)])>
                                @if ($isRiegel)
                                    <td style="min-width:160px">
                                        <div style="font-weight:700">{{ $row['label'] }}</div>
                                        <div class="meta" style="font-size:12px">Calculé · VMA {{ (int) $vmaLent }} → {{ (int) $vmaRapide }} km/h</div>
                                    </td>
                                    @foreach ($distances as $key => $d)
                                        <td class="meta" style="white-space:nowrap">{{ $pct($riegel[$key][0]) }} – {{ $pct($riegel[$key][1]) }} %</td>
                                    @endforeach
                                @else
                                    <td style="min-width:160px"><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="rows.{{ $i }}.label" placeholder="Débutant"></div></td>
                                    @foreach ($distances as $key => $d)
                                        <td>
                                            <div class="flex ac g4">
                                                <div class="ifield" style="width:64px"><input class="ifield-input" type="number" step="0.5" min="30" max="120" wire:model.blur="rows.{{ $i }}.targets.{{ $key }}.min" aria-label="% minimum {{ $d[0] }}"></div>
                                                <span class="meta">–</span>
                                                <div class="ifield" style="width:64px"><input class="ifield-input" type="number" step="0.5" min="30" max="120" wire:model.blur="rows.{{ $i }}.targets.{{ $key }}.max" aria-label="% maximum {{ $d[0] }}" placeholder="+"></div>
                                            </div>
                                        </td>
                                    @endforeach
                                @endif
                                <td><x-toggle :on="$row['active'] ?? true" wire:click="toggleActive({{ $i }})" aria-label="Proposé aux membres : {{ $row['label'] ?: 'niveau '.($i + 1) }}" /></td>
                                <td style="white-space:nowrap">
                                    @if ($i > 0)
                                        <button type="button" class="iconbtn" wire:click="moveUp({{ $i }})" title="Monter" aria-label="Monter"><x-icon name="chevron-up" :size="16" /></button>
                                    @endif
                                    @unless ($isRiegel)
                                        <button type="button" class="iconbtn" wire:click="removeRow({{ $i }})" title="Retirer" aria-label="Retirer"><x-icon name="trash" :size="16" /></button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
