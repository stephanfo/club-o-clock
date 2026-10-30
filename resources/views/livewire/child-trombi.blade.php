{{-- Trombinoscope d'un enfant (#72) — page du parent garant, ouverte depuis « Mes enfants ». --}}
<div class="form-screen">
    <x-topbar :title="$child->first_name" sub="Trombinoscope" :back="route('children')" back-label="Retour à mes enfants">
        <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
    </x-topbar>

    <div class="dk-topbar">
        <a href="{{ route('children') }}" class="btn btn-ghost btn-sm" onclick="return !window.clubBack?.()">
            <x-icon name="chevron-left" :size="15" /> Mes enfants
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">Trombinoscope de {{ $child->first_name }}</div>
        </div>
    </div>

    <div class="dk-body" style="max-width:620px;margin:0 auto">
        <livewire:trombi-settings :subject-id="$child->id" :key="'trombi-child-'.$child->id" />
    </div>
</div>
