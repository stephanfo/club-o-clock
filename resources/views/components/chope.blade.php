{{-- Chope (apéro) — mug dédié, porté de icons.jsx <Chope> (hors ICON_PATHS). --}}
@props(['size' => 16])
<svg {{ $attributes->merge(['class' => 'ic']) }} width="{{ $size }}" height="{{ $size }}"
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
     stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
    <path d="M6 7h10v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7z" />
    <path d="M16 10h2.5a2 2 0 0 1 2 2v2.5a2 2 0 0 1-2 2H16" />
    <path d="M9 11c.7 1 0 2-.6 3s0 2 .6 3M12.8 11c.7 1 0 2-.6 3s0 2 .6 3" />
    <path d="M6 7c0-1.5 1.4-2.5 3-2.5s1.6 1.2 3 1.2 1.5-1.2 3-1.2 1 1 1 2.5" />
</svg>
