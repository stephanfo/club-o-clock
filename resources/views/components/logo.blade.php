{{-- Logo du club — porté de ui.jsx <Logo>. dark = sur fond sombre, sm = compact.
     Image + nom pilotés par ClubSettings (plan open source OS2) : logo uploadé si présent,
     sinon logo neutre par défaut ; nom affiché en entier. Le design d'origine découpait le nom
     en « premier mot + reste » pour les styliser différemment : abandonné, car ce découpage n'a
     de sens que pour un nom en deux parties et casse sur un nom de club arbitraire.
     Vignette 64px (logoThumbUrl) plutôt que l'original : affiché à 24-30px CSS, un fichier
     uploadé arbitraire (photo, export web) downscalé à la volée par le navigateur part flou. --}}
@props(['dark' => false, 'sm' => false])
@php
    $settings = \App\Models\ClubSettings::current();
    $logoSrc = $settings->logoThumbUrl(64);
@endphp
<span {{ $attributes->merge(['class' => 'pa-logo' . ($dark ? ' on-dark' : '') . ($sm ? ' sm' : '')]) }}>
    <img class="pa-logo-flower" src="{{ $logoSrc }}" alt="">
    <span>
        <span class="pa-logo-word">{{ $settings->name }}</span>
        <span class="pa-logo-bars"><i></i><i></i><i></i></span>
    </span>
</span>
