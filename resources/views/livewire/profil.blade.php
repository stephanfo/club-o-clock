{{-- Profil utilisateur — porté de screen-profil.jsx (Profil mobile + ProfilDesktop). Deux coquilles,
     une seule visible par breakpoint (CSS, comme l'accueil). Onglets partagés via le partial _panel. --}}
@php
    $roles = $user->roleLabels();
    $roleSub = $user->roleSummary();
    $memberSince = $user->created_at?->locale('fr')->isoFormat('MMMM YYYY');
    $tabItems = [
        ['v' => 'identite', 'l' => 'Identité'],
        ['v' => 'notifs', 'l' => 'Notifs'],
        ['v' => 'allures', 'l' => 'Allures'],
        ['v' => 'trombi', 'l' => 'Trombi'],
        ['v' => 'connexion', 'l' => 'Connexion'],
    ];
    $navIcons = ['identite' => 'user', 'notifs' => 'bell', 'allures' => 'footprints', 'trombi' => 'users', 'connexion' => 'shield'];
@endphp
<div class="profil-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ─── MOBILE ─── --}}
    <div class="profil-mobile">
        {{-- Le profil s'ouvre depuis le hub Club (#150) : le chevron y ramène. --}}
        <x-topbar :title="$user->fullName()" :sub="$roleSub" :back="route('club')" back-label="Retour au club">
            <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
        </x-topbar>
        <x-tabs :items="$tabItems" :value="$tab" wire-set="tab" class="tabstrip-fit" />
        <div class="pa-scroll" style="padding:16px;background:var(--app-bg)">
            @include('livewire.profil._panel', ['coquille' => 'mobile'])

            {{-- Trombinoscope, infos et mentions légales : dans le hub Club (#150). --}}
        </div>
    </div>

    {{-- ─── DESKTOP ─── --}}
    <div class="profil-desktop">
        <div class="dk-topbar">
            <x-avatar :name="$user->fullName()" size="lg" tint="tint-run" />
            <div class="f1">
                <div class="dsp" style="font-size:26px">{{ $user->fullName() }}</div>
                <div class="meta">{{ $roleSub }}@if ($memberSince) · membre depuis {{ $memberSince }}@endif</div>
            </div>
        </div>
        <div class="dk-body" style="padding:0">
            <div class="profil-dk-split">
                <nav class="profil-dk-nav" aria-label="Sections du profil">
                    @foreach ($tabItems as $it)
                        <button type="button" wire:click="$set('tab', '{{ $it['v'] }}')"
                            class="dk-navitem {{ $tab === $it['v'] ? 'on' : '' }}">
                            <x-icon :name="$navIcons[$it['v']]" /> {{ $it['l'] }}
                        </button>
                    @endforeach
                </nav>
                <div class="profil-dk-content">
                    <div style="max-width:620px;margin:0 auto">
                        @include('livewire.profil._panel', ['coquille' => 'desktop'])
                        <a href="{{ route('legal') }}" class="auth-fine" style="display:inline-block;margin-top:18px">
                            Mentions légales &amp; confidentialité
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
