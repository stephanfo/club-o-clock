{{-- Hub « Club » (#150) — index mobile des écrans hors barre du bas. Rangées reprises de
     l'entrée trombinoscope de l'accueil (card row row-press), intertitres comme la barre latérale.
     Section « Administration » (admins) : un écran n'y entre qu'une fois adapté au mobile, jamais
     avant (#152 adhérents, #153 envois, journaux, tableau de bord, infos). Les réglages (paramètres,
     catalogues, allures, modèles) restent au bureau et n'y figurent pas. --}}
@php
    // color:inherit + bordure explicite : sans elles, la règle globale `a` (club-tokens.css) teinte
    // le libellé en couleur de lien et souligne la rangée.
    $rowStyle = 'padding:12px 14px;color:inherit;';
    $sep = 'border-bottom:1px solid var(--divider);';
    $last = 'border-bottom:none;';
@endphp
<div class="form-screen">
    <x-flash-float />

    <x-topbar title="Club">
        <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
    </x-topbar>

    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Club</div>
        </div>
    </div>

    <div class="dk-body">
        <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:18px">
            <a href="{{ route('profil') }}" wire:navigate class="card row row-press" style="padding:14px;color:inherit">
                <x-avatar :name="$user->fullName()" tint="tint-run" />
                <div class="f1" style="min-width:0">
                    <div style="font-weight:800;font-size:16px">{{ $user->fullName() }}</div>
                    @if (filled($roleSub))<div class="meta">{{ $roleSub }}</div>@endif
                </div>
                <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
            </a>

            <div>
                <div class="sect-head"><span class="sect-title">Le club</span></div>
                <div class="card" style="overflow:hidden">
                    @if ($canTrombi)
                        <a href="{{ route('trombi') }}" wire:navigate class="row row-press" style="{{ $sep }}{{ $rowStyle }}">
                            <x-icon name="users" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
                            <span class="f1" style="font-weight:700;font-size:14px">Trombinoscope</span>
                            <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                        </a>
                    @endif
                    <a href="{{ route('infos') }}" wire:navigate class="row row-press" style="{{ $sep }}{{ $rowStyle }}">
                        <x-icon name="info" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
                        <span class="f1" style="font-weight:700;font-size:14px">Infos du club</span>
                        <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                    </a>
                    <a href="{{ route('alerts') }}" wire:navigate class="row row-press" style="{{ $last }}{{ $rowStyle }}">
                        <x-icon name="bell" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
                        <span class="f1" style="font-weight:700;font-size:14px">Alertes</span>
                        <x-alert-badge :count="$unreadAlerts" :size="18" />
                        <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                    </a>
                </div>
            </div>

            @if ($isAdmin)
                <div>
                    <div class="sect-head"><span class="sect-title">Administration</span></div>
                    <div class="card" style="overflow:hidden">
                        <a href="{{ route('admin.members') }}" wire:navigate class="row row-press" style="{{ $last }}{{ $rowStyle }}">
                            <x-icon name="users" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
                            <span class="f1" style="font-weight:700;font-size:14px">Adhérents</span>
                            <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                        </a>
                    </div>
                </div>
            @endif

            <a href="{{ route('legal') }}" class="card row row-press" style="{{ $rowStyle }}">
                <x-icon name="shield" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
                <span class="f1" style="font-weight:700;font-size:14px">Mentions légales &amp; confidentialité</span>
                <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
            </a>
        </div>
    </div>
</div>
