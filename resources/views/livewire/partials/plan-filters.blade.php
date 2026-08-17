{{-- Chips de filtre discipline (partagés desktop/mobile). Filtres exclusifs : Tout / discipline / Compét. --}}
<button wire:click="filterAll"
        class="chip {{ $kind === '' && ! $discipline ? 'is-active' : '' }}">Tout</button>
@foreach ($disciplines as $d)
    <button wire:click="filterDiscipline({{ $d->id }})"
            class="chip {{ (int) $discipline === $d->id ? 'is-active chip-'.$d->colorClass() : '' }}">
        <span class="dot dot-{{ $d->colorClass() }}"></span>{{ $d->label }}
    </button>
@endforeach
<button wire:click="filterCompetition"
        class="chip {{ $kind === 'competition' ? 'is-active' : '' }}">Compét.</button>
