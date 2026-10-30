{{-- Fiche d'un adhérent (#72, PRD §4.23.6). $card vient de TrombiPolicy : coordonnées absentes du
     HTML quand le lecteur ne doit pas les voir. Structure reprise de la fiche parcours. --}}
@php
    $phone = $card->phone ? \App\Support\Trombi\PhoneNumber::format($card->phone) : null;
    $parentPhone = ($card->parent['phone'] ?? null) ? \App\Support\Trombi\PhoneNumber::format($card->parent['phone']) : null;
@endphp
<div class="form-screen">
    <x-flash-float />

    <x-topbar :title="$card->name" sub="Trombinoscope" :back="route('trombi')" back-label="Retour au trombinoscope">
        <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
    </x-topbar>

    <div class="dk-topbar">
        <a href="{{ route('trombi') }}" class="btn btn-ghost btn-sm" onclick="return !window.clubBack?.()">
            <x-icon name="chevron-left" :size="15" /> Trombinoscope
        </a>
        <div class="f1"></div>
    </div>

    <div class="dk-body" style="max-width:560px;margin:0 auto">
        <div class="card card-pad" style="display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center">
            <x-avatar :name="$card->name" size="xxl" tint="tint-run" :photo="$card->photo512" />
            <div class="dsp" style="font-size:var(--text-2xl)">{{ $card->name }}</div>
            @if ($card->board || $card->roles || $card->category)
                <div class="trombi-chips">
                    @if ($card->board)
                        <span class="chip chip-ink">{{ $card->board }}</span>
                    @endif
                    @foreach ($card->roles as $role)
                        <span class="chip chip-line">{{ $role }}</span>
                    @endforeach
                    @if ($card->category)
                        <span class="chip">{{ $card->category }}</span>
                    @endif
                </div>
            @endif
            @if ($card->qualifications)
                <div class="trombi-chips" aria-label="Qualifications">
                    @foreach ($card->qualifications as $q)
                        <span class="chip chip-sm {{ $q['cls'] }}" @if ($q['title']) title="{{ $q['title'] }}" @endif>{{ $q['label'] }}</span>
                    @endforeach
                </div>
            @endif
            @if ($card->birthday)
                <div class="meta">Anniversaire le {{ $card->birthday->locale('fr')->isoFormat('D MMMM') }}</div>
            @endif
        </div>

        @if ($card->email || $phone)
            <div class="card card-pad" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                @if ($phone)
                    <a href="tel:{{ $card->phone }}" class="btn btn-ghost btn-block"><x-icon name="smartphone" :size="16" /> {{ $phone }}</a>
                @endif
                @if ($card->email)
                    <a href="mailto:{{ $card->email }}" class="btn btn-ghost btn-block" style="overflow-wrap:anywhere"><x-icon name="mail" :size="16" /> {{ $card->email }}</a>
                @endif
            </div>
        @endif

        @if ($card->parent)
            <div class="card card-pad" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                <div class="eyebrow">Parent : {{ $card->parent['name'] }}</div>
                @if ($parentPhone)
                    <a href="tel:{{ $card->parent['phone'] }}" class="btn btn-ghost btn-block"><x-icon name="smartphone" :size="16" /> {{ $parentPhone }}</a>
                @endif
                @if ($card->parent['email'])
                    <a href="mailto:{{ $card->parent['email'] }}" class="btn btn-ghost btn-block" style="overflow-wrap:anywhere"><x-icon name="mail" :size="16" /> {{ $card->parent['email'] }}</a>
                @endif
            </div>
        @endif

        @if ($card->self)
            <a href="{{ route('profil', ['tab' => 'trombi']) }}" wire:navigate class="btn btn-ghost btn-block" style="margin-top:12px">
                <x-icon name="pencil" :size="15" /> Modifier ma carte
            </a>
        @endif
    </div>
</div>
