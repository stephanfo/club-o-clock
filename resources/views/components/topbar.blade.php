{{-- Topbar verte fixe mobile — chrome commun aux écrans plein-format (hors proto : ajoutée pour
     le mobile PWA, cf. série de fixes 2026-07-12). Factorise les 6 blocs .topbar identiques.
     Le CSS (.topbar / .topbar-title / .topbar-sub) vit dans app.css et n'est pas touché.

     - title / sub : textes du centre (sub optionnel).
     - back : URL de REPLI du bouton retour (chevron) ; backLabel : son aria-label. Absents → pas de
       retour. Le chevron tente d'abord un vrai retour arrière (window.clubBack, cf. resources/js/back.js)
       pour ramener d'où l'on vient avec ses filtres ; il ne suit le href que si l'on est entré
       directement sur la page (lien partagé, nouvel onglet) ou si le JS est indisponible.
     - slot `leading` : contenu à gauche à la place du chevron (ex. avatar du profil).
     - slot `trailing` : contenu à droite (ex. <x-alert-bell dark />).
     Priorité à gauche : slot leading > bouton back. --}}
@props(['title' => '', 'sub' => null, 'back' => null, 'backLabel' => 'Retour'])
<div class="topbar">
    @if (isset($leading))
        {{ $leading }}
    @elseif ($back)
        {{-- PAS de wire:navigate ici, volontairement : il déclenche la navigation dès `mousedown`
             (whenThisLinkIsPressed), donc AVANT tout onclick — le repli partirait systématiquement,
             court-circuitant le retour historique. Même piège que wire:click + wire:navigate empilés.
             Le repli reste un lien ordinaire : rechargement pleine page, sans conséquence puisqu'il
             ne sert qu'en entrée directe (lien partagé, nouvel onglet), déjà hors SPA. --}}
        <a href="{{ $back }}" class="iconbtn" aria-label="{{ $backLabel }}"
           onclick="return !window.clubBack?.()"><x-icon name="chevron-left" /></a>
    @endif
    <div class="f1">
        <div class="topbar-title">{{ $title }}</div>
        @if (filled($sub))<div class="topbar-sub">{{ $sub }}</div>@endif
    </div>
    {{-- Instance de démonstration : la pastille vit DANS la barre (mobile), avant les actions.
         Ailleurs elle flotterait au-dessus du contenu et recouvrirait l'en-tête de page. Ne rend
         rien hors DEMO_MODE — invisible sur une instance de club. --}}
    <x-demo-badge mode="bar" />
    {{ $trailing ?? '' }}
</div>
