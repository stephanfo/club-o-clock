{{-- Bannière — porté de ui.jsx <Banner>. kind = info|warn|danger|green. icon override l'icône par défaut. --}}
@props(['kind' => 'info', 'icon' => null])
@php
    $default = ['info' => 'info', 'warn' => 'alert-triangle', 'danger' => 'alert-triangle', 'green' => 'check'][$kind] ?? 'info';
@endphp
<div {{ $attributes->merge(['class' => 'banner banner-' . $kind]) }}>
    <x-icon :name="$icon ?? $default" />
    <div class="f1">{{ $slot }}</div>
</div>
