{{-- Bannières de statut / d'erreur, communes aux écrans d'auth. --}}
@if (session('status'))
    <x-banner kind="info">{{ session('status') }}</x-banner>
@endif
@foreach ($errors->all() as $message)
    <x-banner kind="danger">{{ $message }}</x-banner>
@endforeach
