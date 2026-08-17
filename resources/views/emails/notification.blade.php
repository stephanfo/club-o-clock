<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::button :url="$actionUrl">
Ouvrir dans l'application
</x-mail::button>

Tu reçois cet email parce que les notifications de ce type sont activées sur ton compte. Tu peux les régler ou tout mettre en pause depuis ton profil.

{{ \App\Models\ClubSettings::current()->name }}
</x-mail::message>
