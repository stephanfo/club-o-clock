{{-- Table club des allures cibles (#114) — % de VMA tenable par niveau × distance.
     Admin, assumé desktop. Structure alignée sur le gestionnaire de catalogues. --}}
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
        <div class="cat-wrap">
            <x-banner kind="info"><div>
                Sert à estimer la VMA depuis un résultat de course et à projeter les temps, sur l'écran
                « Allures course » des membres. <b>Table vide : le modèle de Riegel s'applique</b>
                (exposant 1,06, sans niveau). Laisser le % maximum vide pour une borne ouverte (« 95+ »),
                ou le rendre égal au minimum pour une valeur unique.
            </div></x-banner>

            @if ($errors->any())
                <x-banner kind="warn"><div>{{ $errors->first() }}</div></x-banner>
            @endif

            <div class="card" style="overflow-x:auto">
                @if ($rows === [])
                    <div class="row meta" style="justify-content:center;padding:18px">Aucun niveau : le modèle de Riegel s'applique.</div>
                @else
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Niveau</th>
                                @foreach ($distances as [$label])
                                    <th>{{ $label }} <span style="text-transform:none">(min – max %)</span></th>
                                @endforeach
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                <tr wire:key="level-{{ $i }}">
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
                                    <td style="white-space:nowrap">
                                        @if ($i > 0)
                                            <button type="button" class="iconbtn" wire:click="moveUp({{ $i }})" title="Monter" aria-label="Monter"><x-icon name="chevron-up" :size="16" /></button>
                                        @endif
                                        <button type="button" class="iconbtn" wire:click="removeRow({{ $i }})" title="Retirer" aria-label="Retirer"><x-icon name="trash" :size="16" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
