@extends('layouts.guest')

{{-- Écran de connexion — porté de screen-auth.jsx (AuthMobile + AuthDesktop, vue login).
     Deux coquilles autour du même corps (auth/partials/login-body) :
       · mobile  = héros fondu + sheet blanche (AuthMobile)
       · desktop = split panneau marque / carte (AuthDesktop)
     Une seule visible selon le breakpoint (CSS, zéro JS). --}}
@section('content')
@php($clubName = \App\Models\ClubSettings::current()->name)
{{-- ─── MOBILE : héros fondu + sheet ─────────────────────────────── --}}
<div class="auth-mobile">
    <div class="fondu auth-hero">
        <div style="position:relative;z-index:1">
            <x-logo dark />
            <div class="eyebrow" style="color:var(--brand-200);margin-top:22px">{{ $clubName }}</div>
            <div class="dsp" style="font-size:42px;color:var(--paper);margin-top:6px">Connexion</div>
            {{-- Équivalent mobile de l'accroche du panneau héros desktop : la baseline éditable
                 en admin, qui retombe sur celle du produit tant que le club n'a rien saisi. --}}
            <div style="font-size:var(--text-sm);color:var(--fg-on-dark-soft);margin-top:8px">{{ \App\Models\ClubSettings::current()->effectiveTagline() }}</div>
        </div>
    </div>
    <div class="auth-sheet">
        @include('auth.partials.login-body', ['scope' => 'mo'])
    </div>
</div>

{{-- ─── DESKTOP : split marque / carte ───────────────────────────── --}}
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="eyebrow eyebrow-pink">{{ $clubName }}</div>
            <div class="dsp" style="font-size:40px;color:var(--ink);margin-top:6px">Connexion</div>
            {{-- PAS la baseline ici : le panneau héros, juste à gauche, l'affiche déjà en grand —
                 la répéter à 30 cm ferait doublon. Cette ligne sert l'action en cours. --}}
            <div class="meta" style="font-size:var(--text-sm);margin-top:6px;margin-bottom:22px">Accède à ton planning et à tes inscriptions.</div>
            @include('auth.partials.login-body', ['scope' => 'dk'])
        </div>
    </div>
</div>
@endsection
