{{-- Radio custom — porté de ui.jsx <Radio>. Action via $attributes. --}}
@props(['on' => false])
<button type="button" {{ $attributes->merge(['class' => 'radio' . ($on ? ' on' : '')]) }}
        aria-pressed="{{ $on ? 'true' : 'false' }}"></button>
