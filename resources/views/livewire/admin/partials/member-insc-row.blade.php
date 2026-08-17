{{-- Ligne d'inscription dans l'historique fiche adhérent — porté de screen-adherent.jsx InscRow. --}}
@php
    $s = $r->session;
    $cancelled = $r->status === 'cancelled';
    $st = $regStatus[$r->status] ?? ['l' => $r->status, 'cls' => 'chip-line'];
@endphp
<div class="row" style="align-items:center">
    <x-disc-badge :discipline="$s?->discipline" :size="34" style="flex:0 0 auto" />
    <div class="f1" style="min-width:0">
        <div class="flex ac g6">
            <span style="font-weight:700;font-size:14px;{{ $cancelled ? 'text-decoration:line-through;text-decoration-color:var(--fg-muted)' : '' }}">{{ $s?->title ?? 'Séance' }}</span>
        </div>
        <div class="meta" style="font-size:12px">{{ $s?->start_at?->translatedFormat('j M Y · H:i') }}@if ($s?->location) · {{ $s->location->name }}@endif</div>
    </div>
    <span class="chip chip-sm {{ $st['cls'] }}" style="flex:0 0 auto">{{ $st['l'] }}</span>
</div>
