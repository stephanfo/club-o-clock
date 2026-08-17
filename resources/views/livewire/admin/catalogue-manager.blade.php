{{-- Gestionnaire de catalogue générique (PRD §4.6, §4.17) — porté de screen-catalogues.jsx.
     Liste active + archivés, ajout, renommage inline, archivage soft, restauration, suppression
     dure. Champs selon $type. Reçoit : $def, $active, $archived. --}}
@php
    $isLocation = $type === 'location';
    $nameField = $isLocation ? 'name' : 'label';
    // Bornes d'âge : chevauchement = bornes [min,max] qui se recouvrent entre catégories actives (§4.5).
    $ageConflict = false;
    if ($type === 'category') {
        $sorted = $active->sortBy('age_min')->values();
        foreach ($sorted as $i => $c) {
            if ($i > 0 && $c->age_min <= $sorted[$i - 1]->age_max) { $ageConflict = true; break; }
        }
    }
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    <div class="dk-topbar">
        <a href="{{ route('admin.settings') }}" class="btn btn-ghost btn-sm" wire:navigate>
            <x-icon name="chevron-left" :size="15" /> Paramètres
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">{{ $def['title'] }}</div>
            <div class="meta">{{ $active->count() }} actif·s · {{ $archived->count() }} archivé·s</div>
        </div>
        <button type="button" wire:click="startAdd" class="btn btn-primary btn-sm">
            <x-icon name="plus" :size="15" /> Ajouter
        </button>
    </div>

    <div class="dk-body">
        <div class="cat-wrap">
            @if ($type === 'category')
                @if ($ageConflict)
                    <x-banner kind="danger"><div><b>Chevauchement de bornes détecté.</b> Un âge ne peut appartenir qu'à une catégorie — ajuste les bornes.</div></x-banner>
                @else
                    <x-banner kind="green"><div>Aucun chevauchement de bornes.</div></x-banner>
                @endif
            @endif

            {{-- Formulaire d'ajout en tête --}}
            @if ($editingId === 'new')
                @include('livewire.partials.catalogue-editor', ['mode' => 'create'])
            @endif

            {{-- Liste active --}}
            @php
                // En édition inline d'une ligne, le menu d'autocomplétion d'adresse déborde de la carte :
                // on lève le `overflow:hidden` (sinon le dropdown est clippé et masque les lieux suivants),
                // et on arrondit le bandeau à la main pour compenser le coin perdu.
                $editingInList = $editingId !== null && $editingId !== 'new' && $active->contains('id', $editingId);
            @endphp
            <div class="card" style="overflow:{{ $editingInList ? 'visible' : 'hidden' }}">
                {{-- Bandeau « <titre> actifs » + compteur (proto : strip bg-alt). --}}
                <div class="flex ac jb" style="padding:12px 16px;background:var(--bg-alt);border-bottom:1px solid var(--divider);border-radius:var(--card-radius) var(--card-radius) 0 0">
                    <span class="sect-title">{{ $def['title'] }} actif·s</span>
                    <span class="chip chip-sm chip-line">{{ $active->count() }}</span>
                </div>
                <div style="padding:2px 16px">
                @forelse ($active as $it)
                    @if ($editingId === $it->id)
                        @include('livewire.partials.catalogue-editor', ['mode' => 'edit', 'it' => $it])
                    @else
                        <div class="row">
                            @if ($type === 'discipline')<span class="dot dot-{{ $it->colorClass() }}" style="width:12px;height:12px"></span>@endif
                            @if ($type === 'quota_tag')<span class="chip chip-sm chip-tag">{{ $it->code ?: $it->label }}</span>@endif
                            <div class="f1" style="min-width:0">
                                <div class="flex ac g8">
                                    <span style="font-weight:700;font-size:14px">{{ $it->{$nameField} }}</span>
                                </div>
                                <div class="meta" style="margin-top:2px">@include('livewire.partials.catalogue-meta', ['it' => $it])</div>
                            </div>
                            <div class="flex g4" style="flex:0 0 auto">
                                <button type="button" class="iconbtn" wire:click="startEdit({{ $it->id }})" title="Renommer" aria-label="Renommer"><x-icon name="edit" :size="16" /></button>
                                <button type="button" class="iconbtn" wire:click="archive({{ $it->id }})" title="Archiver" aria-label="Archiver"><x-icon name="trash" :size="16" /></button>
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="row meta" style="justify-content:center">Aucune entrée active.</div>
                @endforelse
                </div>
            </div>

            {{-- Archivés (repliable) --}}
            @if ($archived->isNotEmpty())
                <div x-data="{ open: false }">
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="open = !open">
                        <x-icon name="layers" :size="14" /> Archivés ({{ $archived->count() }})
                    </button>
                    <div x-show="open" x-cloak class="card" style="overflow:hidden;margin-top:8px">
                        <div style="padding:2px 16px">
                        @foreach ($archived as $it)
                            <div class="row" style="opacity:.6">
                                <div class="f1" style="min-width:0">
                                    <div class="flex ac g8">
                                        <span style="font-weight:700;font-size:14px">{{ $it->{$nameField} }}</span>
                                        <span class="chip chip-sm chip-line">archivé</span>
                                    </div>
                                    <div class="meta" style="margin-top:2px">@include('livewire.partials.catalogue-meta', ['it' => $it])</div>
                                </div>
                                <div class="flex g4" style="flex:0 0 auto">
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="restore({{ $it->id }})">
                                        <x-icon name="rotate-ccw" :size="13" /> Restaurer
                                    </button>
                                    <button type="button" class="iconbtn" wire:click="delete({{ $it->id }})" wire:confirm="Supprimer définitivement ? (uniquement si zéro référence)" title="Supprimer définitivement" aria-label="Supprimer définitivement"><x-icon name="trash" :size="16" /></button>
                                </div>
                            </div>
                        @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
