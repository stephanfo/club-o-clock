{{-- Drapeau apéro — porté de ui.jsx <AperoFlag>. --}}
@props(['label' => 'Apéro'])
<span {{ $attributes->merge(['class' => 'apero-flag']) }}><x-chope :size="13" /> {{ $label }}</span>
