{{-- Métadonnées PWA partagées par TOUS les layouts (app + guest) : sans ça, l'écran de login
     (layout guest) n'exposait ni manifest ni icône → à l'« Ajouter à l'écran d'accueil » iOS, faute
     d'apple-touch-icon, capturait la page. Un seul point de vérité pour le head PWA. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
@if (App\Support\DemoMode::enabled())
    {{-- Démo publique (OS7) : rien ne doit être indexé. Un `robots.txt` ne suffirait pas — sur
         mutualisé c'est un fichier statique servi sans passer par Laravel, donc impossible à
         conditionner au .env de l'instance. La balise, elle, suit le drapeau. Le déploiement de
         démo remplace en plus son robots.txt (cf. INSTALL). --}}
    <meta name="robots" content="noindex, nofollow">
@endif
@if (config('club.vapid.public_key'))
    {{-- Clé publique VAPID exposée au front pour la souscription Web Push (J8.6). --}}
    <meta name="vapid-public-key" content="{{ config('club.vapid.public_key') }}">
    @auth
        {{-- Compte connecté : la resynchronisation push (#96) repart tout de suite quand un autre
             compte se connecte sur l'appareil, sans attendre le délai d'un jour. --}}
        <meta name="club-user" content="{{ auth()->id() }}">
    @endauth
@endif
{{-- Couleur de la barre système (barre d'état Android, barre de titre d'une PWA de bureau) : le
     HAUT du dégradé des en-têtes, cf. ClubPalette::themeColor. Même source que le theme_color du
     manifest (ManifestController). iOS l'ignore depuis la version 26 : .barre-etat-ios y supplée. --}}
<meta name="theme-color" content="{{ \App\Support\ClubPalette::themeColor(\App\Models\ClubSettings::current()) }}">
{{-- PWA iOS standalone, SANS apple-mobile-web-app-status-bar-style (#112). black-translucent est
     obsolète (WebKit 317153) : depuis iOS 26.1 la PWA ne dessine plus sous l'heure, et la meta n'y
     laissait qu'un fondu clair avec l'heure en noir. Sans elle, iOS peint la barre d'état de la
     couleur unie de .barre-etat-ios (layouts.app). iOS lit ce réglage À L'INSTALLATION : une icône
     ajoutée avant ce changement garde l'ancien rendu jusqu'à sa réinstallation. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
{{-- Icône « Ajouter à l'écran d'accueil » iOS : iOS ignore largement les icons du manifest et
     s'appuie sur apple-touch-icon (180×180, opaque, sans alpha — sinon fond noir). Sans ce lien,
     iOS captait un écran de la page (login vide hors auth). --}}
<link rel="apple-touch-icon" href="{{ \App\Models\ClubSettings::current()->pwaIconUrl('icon_apple') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ \App\Models\ClubSettings::current()->pwaIconUrl('icon_192') }}">
<link rel="manifest" href="/manifest.webmanifest">
