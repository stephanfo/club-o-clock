{{-- Bandeau d'onglets — porté de ui.jsx <Tabs>.
     items : strings OU ['v'=>..,'l'=>..,'badge'=>..]. value : onglet actif.
     wireMethod / wireSet / alpine : cf. x-segmented. --}}
@props(['items' => [], 'value' => null, 'wireMethod' => null, 'wireSet' => null, 'alpine' => null])
<div {{ $attributes->merge(['class' => 'tabstrip']) }}>
    @foreach ($items as $it)
        @php
            $v = is_array($it) ? $it['v'] : $it;
            $l = is_array($it) ? $it['l'] : $it;
            $b = is_array($it) ? ($it['badge'] ?? null) : null;
        @endphp
        <button type="button" class="tab{{ $v === $value ? ' on' : '' }}"
            @if ($wireMethod) wire:click="{{ $wireMethod }}('{{ $v }}')"
            @elseif ($wireSet) wire:click="$set('{{ $wireSet }}', '{{ $v }}')"
            @elseif ($alpine) x-on:click="{{ $alpine }} = '{{ $v }}'" :class="{ on: {{ $alpine }} === '{{ $v }}' }" @endif
        >{{ $l }}@if ($b !== null)<span class="badge">{{ $b }}</span>@endif</button>
    @endforeach
</div>
