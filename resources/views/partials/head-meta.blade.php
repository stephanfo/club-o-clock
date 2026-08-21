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
@endif
{{-- Même source que le theme_color du manifest (ManifestController) : une valeur en dur ici
     divergerait de la palette réelle dès que les couleurs de démarrage sont retouchées. --}}
<meta name="theme-color" content="{{ \App\Models\ClubSettings::current()->primary_color ?: \App\Support\ClubPalette::DEFAULTS['primary_color'] }}">
{{-- PWA iOS standalone. Style « black-translucent » = barre d'état TRANSPARENTE : le contenu
     (donc le dégradé vert de la topbar) remonte derrière l'heure / le Dynamic Island au lieu
     d'une bande opaque blanche. La topbar réserve la hauteur réelle via env(safe-area-inset-top)
     (--topbar-st) : le fond passe sous la barre, le titre/chevron restent dessous. iOS force les
     icônes système en blanc → lisibles sur le vert foncé. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
{{-- Icône « Ajouter à l'écran d'accueil » iOS : iOS ignore largement les icons du manifest et
     s'appuie sur apple-touch-icon (180×180, opaque, sans alpha — sinon fond noir). Sans ce lien,
     iOS captait un écran de la page (login vide hors auth). --}}
<link rel="apple-touch-icon" href="{{ \App\Models\ClubSettings::current()->pwaIconUrl('icon_apple') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ \App\Models\ClubSettings::current()->pwaIconUrl('icon_192') }}">
<link rel="manifest" href="/manifest.webmanifest">
