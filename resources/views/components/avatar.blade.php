{{-- Avatar (initiales) — porté de ui.jsx <Avatar>.
     size = sm|lg|xl (défaut md). tint = tint-swim|tint-bike|tint-run.
     photo = URL de la photo du trombinoscope (#72), déjà filtrée par TrombiPolicy : sans elle, les
     initiales. Chargement différé — une grille de 150 cartes ne télécharge que ce qui s'affiche. --}}
@props(['name' => '', 'size' => null, 'tint' => null, 'photo' => null])
@php
    // initials(name) du proto : 2 premières initiales en majuscules.
    $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = mb_strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    $cls = 'avatar' . ($size ? ' ' . $size : '') . ($tint ? ' ' . $tint : '') . ($photo ? ' has-photo' : '');
@endphp
<span {{ $attributes->merge(['class' => $cls]) }}>@if ($photo)<img src="{{ $photo }}" alt="" loading="lazy" decoding="async">@else{{ $initials }}@endif</span>
