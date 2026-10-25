<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @include('partials.head-meta')
    <title>{{ $title ?? \App\Models\ClubSettings::current()->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.club-palette')
</head>
<body>
    {{-- Bande que WebKit iOS échantillonne pour peindre la barre d'état (#112, cf. app.css). --}}
    <div class="barre-etat-ios barre-etat-ios--auth" aria-hidden="true"></div>
    @yield('content')
    @include('partials.sw-register')
</body>
</html>
