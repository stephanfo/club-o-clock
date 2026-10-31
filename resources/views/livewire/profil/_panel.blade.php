{{-- Aiguillage d'onglet — inclus par chaque coquille (mobile/desktop). --}}
@switch($tab)
    @case('notifs')
        @include('livewire.profil._notifs')
        @break
    @case('allures')
        {{-- Composant imbriqué (#114), une instance par coquille : clé distincte. --}}
        <livewire:allures :key="'allures-'.$coquille" />
        @break
    @case('trombi')
        {{-- Composant imbriqué (#72), une instance par coquille : clé distincte. --}}
        <livewire:trombi-settings :subject-id="$user->id" :key="'trombi-'.$coquille" />
        @break
    @case('connexion')
        @include('livewire.profil._connexion')
        @break
    @default
        @include('livewire.profil._identite')
@endswitch
