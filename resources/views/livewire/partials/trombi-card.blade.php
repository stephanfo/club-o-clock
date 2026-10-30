{{-- Carte du trombinoscope (#72, PRD §4.23.6). $card : App\Support\Trombi\TrombiCard, déjà filtrée
     par TrombiPolicy — rien à masquer ici. $href : lien vers la fiche, null pour un aperçu inerte
     (onglet du profil). --}}
@php
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} class="scard trombi-card" @if ($href) href="{{ $href }}" wire:navigate @endif>
    <x-avatar :name="$card->name" size="xl" tint="tint-run" :photo="$card->photo192" />
    <div class="trombi-card-name">{{ $card->name }}</div>
    @if ($card->board || $card->roles || $card->category)
        <div class="trombi-chips">
            @if ($card->board)
                <span class="chip chip-sm chip-ink">{{ $card->board }}</span>
            @endif
            @foreach ($card->roles as $role)
                <span class="chip chip-sm chip-line">{{ $role }}</span>
            @endforeach
            @if ($card->category)
                <span class="chip chip-sm">{{ $card->category }}</span>
            @endif
        </div>
    @endif
    @if ($card->qualifications)
        <div class="trombi-chips">
            @foreach ($card->qualifications as $q)
                <span class="chip chip-sm {{ $q['cls'] }}" @if ($q['title']) title="{{ $q['title'] }}" @endif>{{ $q['label'] }}</span>
            @endforeach
        </div>
    @endif
    @if ($card->birthday)
        <div class="meta" style="font-size:var(--text-xs)">Anniversaire le {{ $card->birthday->locale('fr')->isoFormat('D MMMM') }}</div>
    @endif
</{{ $tag }}>
