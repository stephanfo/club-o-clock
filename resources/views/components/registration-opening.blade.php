{{-- État des inscriptions officielles d'une compétition (#105, PRD §4.7). Rien sans date d'ouverture,
     sur une course annulée ou déjà partie (Session::registrationState).
     variant : chip (listes : vue Courses, accueil) | text (fiche, à côté du lien organisateur). --}}
@props(['session', 'variant' => 'chip'])
@php
    $state = $session->registrationState();
    $opens = $session->registrationOpensLocal()?->locale('fr');
    $heure = $opens && $session->registration_opens_has_time ? ' à '.$opens->format('H:i') : '';
    $label = match ($state) {
        'today' => 'Inscriptions aujourd\'hui'.$heure,
        'upcoming' => 'Inscriptions le '.$opens->isoFormat('ddd D MMM').$heure,
        'open' => 'Inscriptions ouvertes',
        'maybe_full' => 'Ouvertes depuis le '.$opens->isoFormat('D MMM').' · peut-être complet',
        default => null,
    };
    $chip = match ($state) {
        'today' => 'chip-warn',
        'open' => 'chip-green',
        default => '',
    };
@endphp
@if ($label !== null)
    @if ($variant === 'text')
        {{-- Fiche : la date complète toujours, l'état en chip dès qu'il ne se lit plus dans la date. --}}
        <span {{ $attributes }}>
            {{ ucfirst($opens->isoFormat('dddd D MMMM')).$heure }}
            @if ($state !== 'upcoming')
                <span class="chip chip-sm {{ $chip }}" data-inscriptions="{{ $state }}">{{ ['today' => 'Aujourd\'hui', 'open' => 'Ouvertes', 'maybe_full' => 'Peut-être complet'][$state] }}</span>
            @endif
        </span>
    @else
        <span {{ $attributes->merge(['class' => 'chip chip-sm '.$chip]) }} data-inscriptions="{{ $state }}"><x-icon name="calendar" :size="12" /> {{ $label }}</span>
    @endif
@endif
