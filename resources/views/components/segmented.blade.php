{{-- Contrôle segmenté — porté de ui.jsx <Segmented>.
     items : liste de strings OU de ['v'=>..,'l'=>..]. value : valeur active.
     wireMethod : méthode Livewire appelée avec la valeur (wire:click="m('v')").
     wireSet : propriété Livewire à affecter directement (wire:click="$set('prop','v')").
     alpine : variable Alpine à assigner (x-on:click="var='v'") si pas de Livewire. --}}
@props(['items' => [], 'value' => null, 'wireMethod' => null, 'wireSet' => null, 'alpine' => null])
<div {{ $attributes->merge(['class' => 'seg']) }}>
    @foreach ($items as $it)
        @php $v = is_array($it) ? $it['v'] : $it; $l = is_array($it) ? $it['l'] : $it; @endphp
        <button type="button" class="seg-item{{ $v === $value ? ' on' : '' }}"
            @if ($wireMethod) wire:click="{{ $wireMethod }}('{{ $v }}')"
            @elseif ($wireSet) wire:click="$set('{{ $wireSet }}', '{{ $v }}')"
            @elseif ($alpine) x-on:click="{{ $alpine }} = '{{ $v }}'" :class="{ on: {{ $alpine }} === '{{ $v }}' }" @endif
        >{{ $l }}</button>
    @endforeach
</div>
