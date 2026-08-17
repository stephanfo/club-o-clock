{{-- Sélecteur de sujet parent (§4.2) — porté de shell.jsx <SubjectSwitcher>.
     Le parent reste lui-même ; il bascule la personne consultée (Moi / chaque enfant).
     Attend : $subjectWards, $subjectUser + le composant hôte expose setSubject(). --}}
@php($dark = $dark ?? false)
@php($inline = $inline ?? false)
@if ($subjectWards->isNotEmpty())
    <div class="subj-switch {{ $dark ? 'on-dark' : '' }} {{ $inline ? 'is-inline' : '' }}">
        <span class="subj-lbl">Tu consultes</span>
        <div class="subj-pills">
            <button class="subj-pill {{ $subjectUser->id === auth()->id() ? 'on' : '' }}" wire:click="setSubject(null)">
                <x-avatar :name="auth()->user()->fullName()" size="sm" tint="tint-bike" />
                <span>Moi</span>
            </button>
            @foreach ($subjectWards as $i => $ward)
                <button class="subj-pill {{ $subjectUser->id === $ward->id ? 'on' : '' }}" wire:click="setSubject({{ $ward->id }})">
                    <x-avatar :name="$ward->fullName()" size="sm" :tint="['tint-swim', 'tint-run', 'tint-bike'][$i % 3]" />
                    <span>{{ $ward->first_name }}</span>
                </button>
            @endforeach
        </div>
    </div>
@endif
