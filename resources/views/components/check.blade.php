{{-- Checkbox custom — porté de ui.jsx <Check>. Action via $attributes (wire:click / x-on:click). --}}
@props(['on' => false])
<button type="button" {{ $attributes->merge(['class' => 'check' . ($on ? ' on' : '')]) }}
        aria-pressed="{{ $on ? 'true' : 'false' }}"><x-icon name="check" /></button>
