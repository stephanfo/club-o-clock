@extends('layouts.guest')

{{-- Saisie d'un code déjà reçu (§4.1.1). C'est le chemin de la PWA iOS : le lien a été demandé
     depuis Safari, l'utilisateur ouvre ensuite l'application installée où la session est vierge. --}}
@section('content')
@include('auth.partials.auth-shell', [
    'titre' => 'J\'ai un code',
    'sous' => 'Saisis le code reçu par email pour te connecter ici.',
    'corps' => 'auth.partials.magic-code-body',
    'envoye' => false,
])
@endsection
