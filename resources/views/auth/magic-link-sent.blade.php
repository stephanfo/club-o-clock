@extends('layouts.guest')

{{-- « Un email t'attend » : le lien est parti, et le code se saisit ici (§4.1.1).
     Écran RIGOUREUSEMENT identique que le compte existe ou non — c'est ce qui préserve
     l'anti-énumération malgré le passage d'un simple back() à une redirection. --}}
@section('content')
@include('auth.partials.auth-shell', [
    'titre' => 'Vérifie ta boîte mail',
    'sous' => 'Clique le lien reçu, ou saisis le code ci-dessous.',
    'corps' => 'auth.partials.magic-code-body',
    'envoye' => true,
])
@endsection
