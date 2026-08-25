{{-- Éditeur de ligne de catalogue (création/édition inline) — porté de RowEditor / LieuForm.
     Champs selon $type. Reçoit : $mode ('create'|'edit'), $it? (en édition). --}}
<div class="card card-soft card-pad" style="display:flex;flex-direction:column;gap:10px">
    <div class="eyebrow">{{ $mode === 'create' ? 'Ajouter — '.$def['singular'] : 'Modifier' }}</div>

    @error('form.label')<span class="meta" style="color:var(--danger-border)">{{ $message }}</span>@enderror
    @error('form.name')<span class="meta" style="color:var(--danger-border)">{{ $message }}</span>@enderror
    @error('form.age_max')<span class="meta" style="color:var(--danger-border)">{{ $message }}</span>@enderror

    <div class="flex g8 wrap" style="align-items:flex-end">
        @if ($type === 'location')
            {{-- Saisie de lieu en lignes (§4.13.4). L'autocomplétion adresse pilote tout : choisir une
                 suggestion remplit nom (si vide), adresse, type (si vide) et coordonnées → plus de
                 géocodage manuel. Le champ libre par séance reste, lui, du texte simple. --}}
            <div style="flex-basis:100%;min-width:100%;display:flex;flex-direction:column;gap:10px">
                {{-- Ligne 1 : nom + type --}}
                <div class="flex g8 wrap" style="align-items:flex-end">
                    <div class="f1" style="min-width:180px"><label class="field-label">Nom du lieu</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.name" autofocus></div></div>
                    <div style="width:180px"><label class="field-label">Type</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.kind" placeholder="Piscine…"></div></div>
                </div>

                {{-- Ligne 2 : adresse en autocomplétion (suggestions style carte : titre + adresse + type) --}}
                <div style="position:relative">
                    <label class="field-label">Adresse</label>
                    <div class="ifield">
                        <x-icon name="search" :size="15" style="color:var(--fg-muted);flex:0 0 auto" />
                        <input class="ifield-input" type="text" wire:model.live.debounce.400ms="form.address" placeholder="Cherche une adresse ou un nom de lieu…" autocomplete="off">
                        <span wire:loading wire:target="form.address" class="meta" style="font-size:11px;flex:0 0 auto">…</span>
                    </div>
                    @if (count($addressSuggestions))
                        {{-- z-index élevé : la carte Leaflet en dessous monte ses panes jusqu'à ~700. --}}
                        <div class="card" style="position:absolute;z-index:1200;left:0;right:0;margin-top:4px;max-height:280px;overflow:auto;box-shadow:var(--shadow-md)">
                            @foreach ($addressSuggestions as $i => $s)
                                <button type="button" class="row-press" wire:click="pickSuggestion({{ $i }})" wire:key="sugg-{{ $i }}"
                                        style="display:flex;gap:10px;align-items:flex-start;width:100%;text-align:left;padding:10px 12px;background:none;border:none;border-bottom:1px solid var(--divider);cursor:pointer">
                                    <x-icon name="map-pin" :size="15" style="color:var(--brand);flex:0 0 auto;margin-top:2px" />
                                    <span style="min-width:0;flex:1">
                                        <span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                            <span style="font-weight:600;font-size:13.5px">{{ $s['name'] ?? '' }}</span>
                                            @if (! empty($s['type']))<span class="chip chip-line chip-sm">{{ $s['type'] }}</span>@endif
                                        </span>
                                        <span class="meta" style="display:block;font-size:12px;margin-top:1px;line-height:1.3">{{ $s['address'] ?? '' }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Ligne 3 : coordonnées (auto-remplies par la sélection, modifiables) --}}
                <div class="flex g8 wrap" style="align-items:flex-end">
                    <div style="width:160px"><label class="field-label">Latitude</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.latitude" placeholder="47.37"></div></div>
                    <div style="width:160px"><label class="field-label">Longitude</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.longitude" placeholder="-1.17"></div></div>
                </div>

                {{-- Aperçu cartographique : marqueur sur le lieu géocodé, recentré en direct via `location-located`. --}}
                @if (! empty($form['latitude']) && ! empty($form['longitude']))
                    <div wire:ignore>
                        <div x-data="locationMap({ lat: {{ (float) $form['latitude'] }}, lng: {{ (float) $form['longitude'] }} })"
                             x-on:location-located.window="relocate($event.detail)">
                            <div x-ref="map" class="loc-map"></div>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div class="f1" style="min-width:140px"><label class="field-label">Libellé</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.label" autofocus></div></div>
            @if ($type === 'category')
                <div style="width:90px"><label class="field-label">Âge min</label><div class="ifield"><input class="ifield-input" type="number" min="0" wire:model.blur="form.age_min"></div></div>
                <div style="width:90px"><label class="field-label">Âge max</label><div class="ifield"><input class="ifield-input" type="number" min="0" wire:model.blur="form.age_max"></div></div>
            @endif
            @if ($type === 'quota_tag')
                <div style="width:120px"><label class="field-label">Code</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.code" placeholder="piscine"></div></div>
                <div style="width:100px"><label class="field-label">Max / sem.</label><div class="ifield"><input class="ifield-input" type="number" min="1" max="14" wire:model.blur="form.max_per_week"></div></div>
            @endif
            @if ($type === 'qualification')
                <div style="width:120px"><label class="field-label">Code court</label><div class="ifield"><input class="ifield-input" type="text" wire:model.blur="form.code" placeholder="BNSSA"></div></div>
            @endif
        @endif
    </div>

    <div class="flex g8" style="justify-content:flex-end">
        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Annuler</button>
        <button type="button" class="btn btn-primary btn-sm" wire:click="saveRow">Enregistrer</button>
    </div>
</div>
