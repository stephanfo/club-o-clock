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

    {{-- Ordre du DOM, pas seulement visuel : avec une VMA enregistrée, les allures passent en tête
         (ce qu'on vient consulter) ; sans, l'estimation d'abord, puis la saisie. --}}
    @if ($reference)
        @include('livewire.allures._zones')
    @else
        @include('livewire.allures._estimation')
    @endif

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

    @if ($reference)
        @include('livewire.allures._estimation')
    @else
        {{-- VMA tapée sans être enregistrée : les allures s'affichent sous la saisie. --}}
        @include('livewire.allures._zones')
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
