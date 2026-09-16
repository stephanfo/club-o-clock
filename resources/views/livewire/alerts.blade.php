{{-- Alertes — porté de screen-alerts.jsx (Alerts mobile + AlertsDesktop).
     Source : NotificationOutbox::alertsFor (visibilité et masquage #79), cartes regroupées par Alerts::group. --}}
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
                    <div class="flex" style="justify-content:flex-end">
                        <button type="button" class="btn btn-ghost btn-sm"
                                wire:click="openDismissAllConfirm"
                                wire:loading.attr="disabled" wire:target="openDismissAllConfirm">Tout effacer</button>
                    </div>
                    @foreach ($alerts as $alert)
                        @include('livewire.partials.alert-item', ['alert' => $alert, 'shell' => 'm'])
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
            @if ($alerts->isNotEmpty())
                <button type="button" class="btn btn-ghost btn-sm"
                        wire:click="openDismissAllConfirm"
                        wire:loading.attr="disabled" wire:target="openDismissAllConfirm">Tout effacer</button>
            @endif
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
                        @include('livewire.partials.alert-item', ['alert' => $alert, 'shell' => 'd'])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Dialog « Tout effacer » — niveau 2 (destructif, sans accusé de réception) : le geste ne
         notifie personne et n'engage que la liste de celui qui le fait, mais il ne se défait pas
         depuis l'écran et porte au-delà de ce qui est affiché. Pas de chiffre : une carte peut
         regrouper plusieurs lignes d'outbox, annoncer un nombre de lignes mentirait sur ce qui
         disparaît, et la liste plafonne de toute façon à MAX_CARDS. --}}
    @if ($confirmingDismissAll)
        <x-dialog danger title="Retirer toutes les alertes" :width="480" close="dismissDismissAllConfirm">
            <div style="display:flex;flex-direction:column;gap:12px">
                <x-conseq-row icon="eye-off" label="Toute la liste" tone="warn">
                    Toutes tes alertes disparaissent de cet écran, y compris les plus anciennes que la liste ne montre pas.
                </x-conseq-row>
                <x-conseq-row icon="bell" label="Sans effet sur les envois">
                    Rien n'est supprimé : les notifications restent tracées, et les prochaines s'afficheront normalement.
                </x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="dismissDismissAllConfirm">Annuler</button>
                <button type="button" class="btn btn-danger"
                        wire:click="dismissAll" wire:loading.attr="disabled" wire:target="dismissAll">
                    <x-icon name="eye-off" :size="14" /> Tout retirer
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
