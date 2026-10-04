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

    {{-- Ordre du DOM, pas seulement visuel : avec une VMA enregistrée, les allures et la projection
         passent en tête (ce qu'on vient consulter), le niveau juste au-dessus de la projection qu'il
         règle ; sans, le niveau et l'estimation d'abord, puis la saisie. --}}
    @if ($reference)
        @include('livewire.allures._zones')
        @include('livewire.allures._niveau')
        @include('livewire.allures._projection')
    @else
        @include('livewire.allures._niveau')
        @include('livewire.allures._estimation')
    @endif

    {{-- ── Ma VMA ── --}}
    <div class="card card-pad" wire:key="al-ma-vma">
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
            {{-- Saisie libre, ou ajustement au dixième par − / + : la valeur passe par l'input (même
                 anti-rebond que la frappe), bornée à la plage plausible du référentiel. --}}
            <div x-data="{ pas(d) {
                    const i = this.$refs.vma, [lo, hi] = [{{ $referentiel->bounds()[0] }}, {{ $referentiel->bounds()[1] }}];
                    let v = parseFloat((i.value || '').replace(',', '.'));
                    v = isNaN(v) ? {{ $reference?->value ?? 13 }} : Math.min(hi, Math.max(lo, Math.round((v + d) * 10) / 10));
                    i.value = v.toFixed(1).replace('.', ',');
                    i.dispatchEvent(new Event('input'));
                } }">
                <label class="field-label" for="al-vma-{{ $this->getId() }}">VMA (km/h)</label>
                <div class="stepper">
                    <button type="button" x-on:click="pas(-0.1)" aria-label="Baisser de 0,1 km/h">−</button>
                    <input id="al-vma-{{ $this->getId() }}" x-ref="vma" class="val" type="text" inputmode="decimal" wire:model.live.debounce.400ms="vma" placeholder="13,5">
                    <button type="button" x-on:click="pas(0.1)" aria-label="Monter de 0,1 km/h">+</button>
                </div>
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
        @include('livewire.allures._projection')
    @endif

</div>
