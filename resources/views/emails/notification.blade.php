<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::button :url="$actionUrl">
Ouvrir dans l'application
</x-mail::button>

@if ($transactional)
Tu reçois cet email parce qu'il porte l'accès à ton compte. Il n'est pas concerné par tes réglages de notifications ni par la mise en pause.
@else
Tu reçois cet email parce que les notifications de ce type sont activées sur ton compte. Tu peux les régler ou tout mettre en pause depuis ton profil.
@endif

{{ \App\Models\ClubSettings::current()->name }}
</x-mail::message>
