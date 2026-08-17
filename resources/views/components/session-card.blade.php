{{-- Carte de séance unifiée — porté de ui.jsx <SessionRow> + variantes planning.
     variant : row (liste home/agenda/semaine mobile) | week (colonne planning desktop) | pill (cellule mois).
     showDate : variante row → empile jour/date/heure à gauche au lieu du disc-badge. --}}
@props([
    'session',
    'tz',
    'variant' => 'row',
    'showDate' => true,
    'viewAs' => null,         // id du sujet consulté (parent → enfant, §4.2) ; défaut = soi
    'subjectName' => null,    // prénom de l'enfant consulté → « Hugo participe » (ui.jsx EnrollChip)
    'linkAs' => null,         // id d'enfant à poser en sujet via l'URL (?as=) — §4.2 « Mes enfants »
])
@php
    $s = $session;
    $cls = $s->colorClass();
    $icon = $s->discipline?->icon() ?? 'calendar';
    $start = $s->start_at->copy()->setTimezone($tz);
    $participating = $s->registrations->where('status', 'participating')->count();
    $full = $s->capacity && $participating >= $s->capacity;
    $loc = $s->location_text ?: $s->location?->name;
    $cancelled = $s->isCancelled();
    $insLabel = $s->capacity
        ? ($full ? 'complet' : $participating.'/'.$s->capacity)
        : $participating.' inscrit'.($participating > 1 ? 's' : '');
    // §4.2 : depuis « Mes enfants », l'URL porte le sujet enfant (?as=) → posé côté serveur dans
    // SessionShow::mount() (pas de course avec wire:navigate), qui redirige ensuite vers l'URL
    // canonique sans ?as=. Ailleurs, lien neutre.
    $href = $linkAs
        ? route('sessions.show', ['session' => $s, 'as' => $linkAs])
        : route('sessions.show', $s);
    // Pastille apéro (§4.14.5 affordance 1) dès qu'un flag actif existe.
    $apero = $s->hasApero();
    // Chips (porté de ui.jsx SessionRow L128-132) : tag quota + statut d'inscription perso.
    $tag = $s->quotaTag?->code ?: $s->quotaTag?->label;
    $uid = $viewAs ?? auth()->id();
    $effectiveStatus = $s->statusFor($uid);
    // « Tu encadres » reste rattaché au compte connecté (le sujet enfant n'encadre jamais).
    $mineCoach = ! $subjectName && auth()->id() && $s->relationLoaded('coaches') && $s->coaches->contains('id', auth()->id());
    $participeLabel = $subjectName ? $subjectName.' participe' : 'Tu participes';
@endphp

@if ($variant === 'pill')
    {{-- Mini-carte (cellule calendrier mois) --}}
    <a {{ $attributes }} href="{{ $href }}" wire:navigate class="scard {{ $cls }} scard-pill {{ $cancelled ? 'scard-cancelled' : '' }}">
        <span class="flex ac g6">
            <span class="dot dot-{{ $cls }}"></span>
            <span class="num" style="font-size:var(--text-xs)">{{ $start->format('H:i') }}</span>
            {{-- Statut perso : icône seule (la largeur d'une cellule de mois n'admet pas de chip),
                 mêmes couleurs que chip-green / chip-warn des variantes row et week.
                 L'annulation prime sur le statut d'inscription (comme la variante week). --}}
            @if ($cancelled)
                <span class="mlauto flex ac scard-pill-status" role="img" aria-label="Séance annulée"
                      title="Séance annulée" style="color:var(--accent)"><x-icon name="x" :size="11" /></span>
            @elseif ($effectiveStatus === 'participating')
                <span class="mlauto flex ac scard-pill-status" role="img" aria-label="{{ $participeLabel }}"
                      title="{{ $participeLabel }}" style="color:var(--brand-700)"><x-icon name="check" :size="11" /></span>
            @elseif ($effectiveStatus === 'waitlist')
                <span class="mlauto flex ac scard-pill-status" role="img" aria-label="Liste d'attente"
                      title="Liste d'attente" style="color:var(--warning-text)"><x-icon name="clock" :size="11" /></span>
            @endif
            {{-- Chope apéro dans le flux (et non en absolu comme les autres variantes) : la ligne
                 d'en-tête porte déjà le marqueur de statut à droite, un absolu le recouvrirait.
                 Sans statut, `mlauto` la pousse quand même à droite. --}}
            @if ($apero)
                <x-chope :size="11" class="{{ $cancelled || $effectiveStatus ? '' : 'mlauto' }}"
                         style="flex:0 0 auto;color:var(--apero)" />
            @endif
        </span>
        <span class="scard-pill-title">{{ $s->title }}</span>
    </a>

@elseif ($variant === 'week')
    {{-- Carte verticale (colonne jour, planning desktop). Carte entièrement cliquable → fiche.
         Pas d'action d'inscription ici (desktop) : trop chargé. L'inscription se fait sur la fiche.
         Le statut perso (participe / file) reste affiché en badge. --}}
    <a {{ $attributes }} href="{{ $href }}" wire:navigate class="scard {{ $cls }} scard-week {{ $cancelled ? 'scard-cancelled' : '' }}">
        <div class="num" style="font-size:14px">{{ $start->format('H:i') }}</div>
        <div class="scard-week-title">{{ $s->title }}</div>
        <div class="meta" style="font-size:11px;margin-top:3px">{{ $full ? 'complet' : $insLabel }}</div>
        @if ($cancelled)
            <span class="chip chip-sm chip-pink" style="margin-top:6px">Annulée</span>
        @elseif ($effectiveStatus === 'participating')
            <span class="chip chip-sm chip-green" style="margin-top:6px"><x-icon name="check" :size="11" /> {{ $participeLabel }}</span>
        @elseif ($effectiveStatus === 'waitlist')
            <span class="chip chip-sm chip-warn" style="margin-top:6px"><x-icon name="clock" :size="11" /> Liste d'attente</span>
        @endif
        @if ($apero)<x-chope :size="14" style="position:absolute;top:8px;right:8px;color:var(--apero)" />@endif
    </a>

@else
    {{-- Ligne horizontale (home, agenda, semaine mobile). Carte entièrement cliquable → fiche.
         L'inscription se fait toujours depuis la fiche (cohérent avec les autres écrans). --}}
    <a {{ $attributes }} href="{{ $href }}" wire:navigate class="scard {{ $cls }} scard-row {{ $cancelled ? 'scard-cancelled' : '' }}">
        @if ($showDate)
            <div class="scard-row-date">
                <div class="eyebrow" style="font-size:10px">{{ $start->locale('fr')->isoFormat('ddd') }}</div>
                <div class="num" style="font-size:22px">{{ $start->format('j') }}</div>
                <div class="meta" style="font-size:11px">{{ $start->format('H:i') }}</div>
            </div>
        @else
            <x-disc-badge :cls="$cls" :icon="$icon" />
        @endif
        <div class="f1" style="min-width:0">
            <div class="flex ac g6" style="min-width:0">
                <span class="scard-row-title">{{ $s->title }}</span>
                @if ($apero)<x-chope :size="14" style="color:var(--apero);flex:0 0 auto" />@endif
            </div>
            <div class="meta" style="margin-top:2px">{{ $loc ? $loc.' · ' : '' }}{{ $insLabel }}</div>
            @if ($cancelled)
                <div class="flex ac g6" style="margin-top:6px"><span class="chip chip-sm chip-pink">Annulée</span></div>
            @else
                <div class="flex ac g6 wrap" style="margin-top:6px">
                    @if ($tag)<span class="chip chip-sm chip-tag">{{ $tag }}</span>@endif
                    @if ($mineCoach)
                        <span class="chip chip-sm" style="background:var(--ink);color:var(--paper)"><x-icon name="whistle" :size="12" /> Tu encadres</span>
                    @elseif ($effectiveStatus === 'participating')
                        <span class="chip chip-sm chip-green"><x-icon name="check" :size="12" /> {{ $participeLabel }}</span>
                    @elseif ($effectiveStatus === 'waitlist')
                        <span class="chip chip-sm chip-warn"><x-icon name="clock" :size="12" /> Liste d'attente</span>
                    @elseif ($full)
                        <span class="chip chip-sm chip-pink">Complet</span>
                    @endif
                </div>
            @endif
        </div>
        <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
    </a>
@endif
