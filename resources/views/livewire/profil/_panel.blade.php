{{-- Aiguillage d'onglet — inclus par chaque coquille (mobile/desktop). --}}
@switch($tab)
    @case('notifs')
        @include('livewire.profil._notifs')
        @break
    @case('quotas')
        @include('livewire.profil._quotas')
        @break
    @case('connexion')
        @include('livewire.profil._connexion')
        @break
    @default
        @include('livewire.profil._identite')
@endswitch
