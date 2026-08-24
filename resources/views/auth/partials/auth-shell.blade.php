{{-- Coquille commune des écrans d'auth secondaires (demande de lien, « email envoyé », saisie du
     code). Reprend la structure de auth/login.blade.php : les deux coquilles coexistent dans le DOM,
     une seule est visible selon le breakpoint (CSS, zéro JS).

     Elle existe pour que ces écrans soient utilisables au TÉLÉPHONE : auth/magic-link.blade.php
     n'avait que la coquille desktop, sur une application majoritairement consultée au mobile.

     Attend : $titre, $sous, $corps (nom de vue à inclure). --}}
@php($clubName = \App\Models\ClubSettings::current()->name)

{{-- ─── MOBILE ─── --}}
<div class="auth-mobile">
    <div class="fondu auth-hero">
        <div style="position:relative;z-index:1">
            <x-logo dark />
            <div class="eyebrow" style="color:var(--brand-200);margin-top:22px">{{ $clubName }}</div>
            <div class="dsp" style="font-size:42px;color:var(--paper);margin-top:6px">{{ $titre }}</div>
            <div style="font-size:var(--text-sm);color:var(--fg-on-dark-soft);margin-top:8px">{{ $sous }}</div>
        </div>
    </div>
    <div class="auth-sheet">
        <div class="auth-body">
            @include($corps, ['scope' => 'mo'])
        </div>
    </div>
</div>

{{-- ─── DESKTOP ─── --}}
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="eyebrow eyebrow-pink">{{ $clubName }}</div>
            <div class="dsp" style="font-size:40px;color:var(--ink);margin-top:6px">{{ $titre }}</div>
            <div class="meta" style="font-size:var(--text-sm);margin-top:6px;margin-bottom:22px">{{ $sous }}</div>
            <div class="auth-body">
                @include($corps, ['scope' => 'dk'])
            </div>
        </div>
    </div>
</div>
