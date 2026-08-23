@extends('layouts.guest')

{{-- Demande d'un lien de connexion (§4.1.1). Mobile ET desktop. --}}
@section('content')
@include('auth.partials.auth-shell', [
    'titre' => 'Connexion',
    'sous' => 'Reçois un lien de connexion, sans mot de passe.',
    'corps' => 'auth.partials.magic-request-body',
])
@endsection
