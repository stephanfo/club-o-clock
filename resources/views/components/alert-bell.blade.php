{{-- Cloche Alertes + pastille de non-lus (revue UX 2026-07-11, constat n°1) : accès aux alertes
     depuis le header des écrans mobiles principaux — sans elle, seule la cloche de l'Accueil y
     menait. Le compte vit dans le composant appelant (re-rendu à chaque requête Livewire). --}}
@props(['dark' => false])
@php($unread = auth()->check() ? \App\Models\NotificationOutbox::unreadCountFor(auth()->id()) : 0)
<a href="{{ route('alerts') }}" wire:navigate class="iconbtn" style="position:relative;{{ $dark ? 'color:var(--fg-on-dark-soft)' : '' }}"
   aria-label="Alertes{{ $unread ? " — {$unread} non lue".($unread > 1 ? 's' : '') : '' }}">
    <x-icon name="bell" />
    <x-alert-badge :count="$unread" :size="16" absolute />
</a>
