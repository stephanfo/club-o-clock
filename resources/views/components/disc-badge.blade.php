{{-- Pastille discipline — porté de ui.jsx <DiscBadge>.
     discipline : modèle App\Models\Discipline (fournit colorClass()+icon()).
     Sinon passer :cls (swim|bike|run|prep) et :icon explicites. size = px (override). --}}
@props(['discipline' => null, 'cls' => null, 'icon' => null, 'size' => null])
@php
    $cls ??= $discipline?->colorClass() ?? 'prep';
    $icon ??= $discipline?->icon() ?? 'calendar';
@endphp
<span {{ $attributes->merge(['class' => 'disc-badge disc-' . $cls]) }}
      @if ($size) style="width:{{ $size }}px;height:{{ $size }}px" @endif>
    <x-icon :name="$icon" style="width:55%;height:55%" />
</span>
