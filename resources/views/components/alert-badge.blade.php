{{-- Pastille de compteur d'alertes non lues. Source unique du rendu (seuil 9+, couleur hibiscus,
     police display) partagée par la sidebar desktop (layout) et la cloche mobile (x-alert-bell).
     `size` = diamètre en px ; `absolute` positionne en coin (cloche) sinon flux inline (sidebar). --}}
@props(['count' => 0, 'size' => 16, 'absolute' => false])
@php
    $d = (int) $size;
    $pos = $absolute
        ? "position:absolute;top:2px;right:2px;"
        : "margin-left:auto;";
    $fs = $d >= 18 ? 11 : 10;
@endphp
@if ($count > 0)
    <span aria-hidden="true"
          style="{{ $pos }}min-width:{{ $d }}px;height:{{ $d }}px;padding:0 {{ $d >= 18 ? 5 : 4 }}px;border-radius:{{ $d / 2 }}px;background:var(--accent);color:var(--paper);font-family:var(--font-display);font-weight:800;font-size:{{ $fs }}px;line-height:{{ $d }}px;text-align:center">{{ $count > 9 ? '9+' : $count }}</span>
@endif
