{{-- Toggle — porté de ui.jsx <Toggle>. État via prop :on ; action via wire:click/x-on:click ($attributes). --}}
@props(['on' => false])
<button type="button" {{ $attributes->merge(['class' => 'toggle' . ($on ? ' on' : '')]) }}
        aria-pressed="{{ $on ? 'true' : 'false' }}"></button>
