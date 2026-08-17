{{-- Avatar (initiales) — porté de ui.jsx <Avatar>.
     size = sm|lg|xl (défaut md). tint = tint-swim|tint-bike|tint-run. --}}
@props(['name' => '', 'size' => null, 'tint' => null])
@php
    // initials(name) du proto : 2 premières initiales en majuscules.
    $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = mb_strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    $cls = 'avatar' . ($size ? ' ' . $size : '') . ($tint ? ' ' . $tint : '');
@endphp
<span {{ $attributes->merge(['class' => $cls]) }}>{{ $initials }}</span>
