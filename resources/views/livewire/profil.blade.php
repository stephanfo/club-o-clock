{{-- Profil utilisateur — porté de screen-profil.jsx (Profil mobile + ProfilDesktop). Deux coquilles,
     une seule visible par breakpoint (CSS, comme l'accueil). Onglets partagés via le partial _panel. --}}
@php
    $roleMap = ['athlete' => 'Athlète', 'coach' => 'Coach', 'admin' => 'Admin'];
    $roles = array_map(fn ($r) => $roleMap[$r] ?? ucfirst($r), $user->roles ?? []);
    $roleSub = implode(' · ', $roles);
    if ($cat = $user->primaryCategory()) {
        $roleSub = trim($roleSub.' · '.$cat->label, ' ·');
    }
    $memberSince = $user->created_at?->locale('fr')->isoFormat('MMMM YYYY');
    $tabItems = [
        ['v' => 'identite', 'l' => 'Identité'],
        ['v' => 'notifs', 'l' => 'Notifs'],
        ['v' => 'quotas', 'l' => 'Quotas'],
        ['v' => 'connexion', 'l' => 'Connexion'],
    ];
    $navIcons = ['identite' => 'user', 'notifs' => 'bell', 'quotas' => 'bar-chart', 'connexion' => 'shield'];
@endphp
<div class="profil-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ─── MOBILE ─── --}}
    <div class="profil-mobile">
        <x-topbar :title="$user->fullName()" :sub="$roleSub">
            <x-slot:leading><x-avatar :name="$user->fullName()" tint="tint-run" /></x-slot:leading>
            <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
        </x-topbar>
        <x-tabs :items="$tabItems" :value="$tab" wire-set="tab" />
        <div class="pa-scroll" style="padding:16px;background:var(--app-bg)">
            @include('livewire.profil._panel')

            {{-- Accès mobile aux pages d'info (revue UX 2026-07-11) : sans ce lien, la page Infos
                 n'est atteignable sur mobile que via une bannière épinglée sur l'Accueil. --}}
            <a href="{{ route('infos') }}" wire:navigate class="btn btn-ghost btn-block" style="margin-top:14px">
                <x-icon name="info" :size="15" /> Infos du club
            </a>
            <a href="{{ route('legal') }}" class="btn btn-ghost btn-block" style="margin-top:6px">
                <x-icon name="shield" :size="15" /> Mentions légales &amp; confidentialité
            </a>
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
                        @include('livewire.profil._panel')
                        <a href="{{ route('legal') }}" class="auth-fine" style="display:inline-block;margin-top:18px">
                            Mentions légales &amp; confidentialité
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
