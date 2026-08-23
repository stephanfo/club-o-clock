{{-- Écran d'accueil après activation d'une invitation (§4.1.3). Un seul contenu (partials/_body),
     rendu dans les deux coquilles mobile / desktop, sur le patron de profil.blade.php. Le compte est
     déjà connecté et son email vérifié : il ne reste qu'un choix de méthode, et il est facultatif. --}}
<div>
    <x-flash-float />

    {{-- ─── MOBILE ─── --}}
    <div class="activation-mobile">
        <x-topbar title="Bienvenue" :sub="$user->fullName()" />
        <div style="padding:16px;background:var(--app-bg);display:flex;flex-direction:column;gap:14px">
            @include('livewire.activation._body')
        </div>
    </div>

    {{-- ─── DESKTOP ─── --}}
    <div class="activation-desktop">
        <div class="dk-topbar">
            <x-avatar :name="$user->fullName()" size="lg" tint="tint-run" />
            <div class="f1">
                <div class="dsp" style="font-size:26px">Bienvenue, {{ $user->first_name }}</div>
                <div class="meta">Ton compte est activé</div>
            </div>
        </div>
        <div class="dk-body">
            <div style="max-width:560px;margin:0 auto;display:flex;flex-direction:column;gap:14px">
                @include('livewire.activation._body')
            </div>
        </div>
    </div>
</div>
