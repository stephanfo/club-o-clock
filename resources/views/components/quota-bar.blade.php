{{-- Barre de quota — porté de ui.jsx <QuotaBar>. Largeur data-driven (inline légitime). --}}
@props(['used' => 0, 'max' => 1])
@php
    $full = $used >= $max;
    $pct = $max > 0 ? min(100, ($used / $max) * 100) : 0;
@endphp
<div {{ $attributes->merge(['class' => 'qbar' . ($full ? ' full' : '')]) }}>
    <i style="width:{{ $pct }}%"></i>
</div>
