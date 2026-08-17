{{-- Alertes — porté de screen-alerts.jsx (Alerts mobile + AlertsDesktop).
     Source : notification_outbox sent/push (60 derniers jours). Pas d'état lu/non-lu en V1. --}}
<div class="alerts-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ═══════════════════════ MOBILE ═══════════════════════ --}}
    <div class="fiche-mobile">
        <x-topbar title="Alertes" :back="route('home')" back-label="Retour accueil">
            <x-slot:trailing>
                <a href="{{ route('profil', ['tab' => 'notifs']) }}" wire:navigate class="iconbtn" title="Préférences notifs" aria-label="Préférences notifs"><x-icon name="settings" /></a>
            </x-slot:trailing>
        </x-topbar>

        <div class="pa-scroll" style="background:var(--app-bg);padding:12px">
            @if ($alerts->isEmpty())
                <div class="card card-pad meta" style="text-align:center;padding:40px">Aucune notification reçue.</div>
            @else
                <div style="display:flex;flex-direction:column;gap:8px">
                    @foreach ($alerts as $alert)
                        @if ($alert['sessionId'])
                            <a href="{{ route('sessions.show', $alert['sessionId']) }}" wire:navigate
                               class="card card-pad flex ac g12" style="text-decoration:none;color:inherit">
                                @include('livewire.partials.alert-card', ['alert' => $alert])
                            </a>
                        @else
                            <div class="card card-pad flex ac g12">
                                @include('livewire.partials.alert-card', ['alert' => $alert])
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════ DESKTOP ═══════════════════════ --}}
    <div class="home-desktop">
        <div class="dk-topbar">
            <div class="f1">
                <div class="dsp" style="font-size:26px">Alertes</div>
            </div>
            <a href="{{ route('profil', ['tab' => 'notifs']) }}" wire:navigate class="btn btn-ghost btn-sm">
                <x-icon name="settings" :size="15" /> Préférences notifs
            </a>
        </div>

        <div class="dk-body">
            @if ($alerts->isEmpty())
                <div class="card card-pad meta" style="text-align:center;padding:40px;max-width:720px;margin:0 auto">Aucune notification reçue.</div>
            @else
                <div style="max-width:720px;margin:0 auto;display:flex;flex-direction:column;gap:10px">
                    @foreach ($alerts as $alert)
                        @if ($alert['sessionId'])
                            <a href="{{ route('sessions.show', $alert['sessionId']) }}" wire:navigate
                               class="card card-pad flex ac g12" style="text-decoration:none;color:inherit">
                                @include('livewire.partials.alert-card', ['alert' => $alert])
                            </a>
                        @else
                            <div class="card card-pad flex ac g12">
                                @include('livewire.partials.alert-card', ['alert' => $alert])
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
