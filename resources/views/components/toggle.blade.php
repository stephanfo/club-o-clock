{{-- Toggle — porté de ui.jsx <Toggle>. État via prop :on ; action via wire:click/x-on:click ($attributes).
     :mixed = état intermédiaire (interrupteur « Tout » du trombinoscope, #72). --}}
@props(['on' => false, 'mixed' => false])
<button type="button" {{ $attributes->merge(['class' => 'toggle' . ($mixed ? ' mixed' : ($on ? ' on' : ''))]) }}
        aria-pressed="{{ $mixed ? 'mixed' : ($on ? 'true' : 'false') }}"></button>
