<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    {{-- PWA plein écran : on bloque tous les zooms. maximum-scale=1 + user-scalable=no coupe le
         pincer (et le zoom auto au focus d'input, complété par font-size≥16px côté CSS). Le
         double-tap est neutralisé par touch-action:manipulation (cf. app.css). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    @include('partials.head-meta')
    <title>{{ $title ?? \App\Models\ClubSettings::current()->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.club-palette')
</head>
<body class="app-body">
    @php
        $u = auth()->user();
        // Onglet de nav actif. Table motif de route => clé, remplaçant un ternaire de 13 niveaux
        // devenu illisible (extraction prévue au doc J10 §10, faite ici en ajoutant « parcours »).
        // L'ORDRE COMPTE : le premier motif qui correspond gagne — 'admin.settings' avant les
        // motifs larges, et 'gpx-routes.*' couvre index/creer/modifier d'un seul tenant.
        $active = $active ?? collect([
            'profil' => 'profil',
            'alerts' => 'alerts',
            'planning' => 'planning',
            'gpx-routes.*' => 'parcours',
            'children' => 'children',
            'infos' => 'infos',
            'dashboard' => 'home',
            'admin.dashboard' => 'admin-dashboard',
            'admin.members*' => 'members',
            'admin.templates*' => 'templates',
            'admin.infos*' => 'admin-infos',
            'admin.journal' => 'admin-journal',
            'admin.outbox' => 'admin-outbox',
            'admin.settings' => 'settings',
            'admin.catalogues' => 'settings',
        ])->first(fn ($key, $pattern) => request()->routeIs($pattern)) ?? '';
        // Tiers de nav empilés selon les rôles cumulés (proto shell.jsx NAV_TIERS).
        $isCoach = $u?->hasRole('coach');
        $isAdmin = $u?->hasRole('admin');
        // Parent garant (§4.2) : relation, pas un rôle — l'entrée « Mes enfants » n'apparaît qu'à lui.
        $isGuardian = $u && \App\Support\SubjectContext::isGuardian($u);
        // Badge alertes non lues (revue UX 2026-07-11) — sidebar desktop. Le layout ne re-rend pas
        // sur une action Livewire, MAIS le seul geste qui marque des alertes lues est l'ouverture
        // de la page Alertes (Alerts::mount), une navigation pleine page qui reconstruit ce layout
        // → le badge est toujours à jour au chargement de chaque écran (pas de polling en V1).
        // Le mobile utilise x-alert-bell dans les headers d'écran (mêmes garanties).
        $unreadAlerts = $u ? \App\Models\NotificationOutbox::unreadCountFor($u->id) : 0;
    @endphp

    <div class="app-layout">
        {{-- Sidebar desktop --}}
        <aside class="dk-side">
            <div class="dk-side-logo">
                <x-logo sm />
            </div>
            <hr class="divider">

            <nav class="dk-side-scroll" aria-label="Navigation">
                <a href="{{ route('dashboard') }}" wire:navigate class="dk-navitem {{ $active === 'home' ? 'on' : '' }}">
                    <x-icon name="home" /> Accueil
                </a>
                <a href="{{ route('planning') }}" wire:navigate class="dk-navitem {{ $active === 'planning' ? 'on' : '' }}">
                    <x-icon name="calendar" /> Planning
                </a>
                <a href="{{ route('gpx-routes.index') }}" wire:navigate class="dk-navitem {{ $active === 'parcours' ? 'on' : '' }}">
                    <x-icon name="route" /> Parcours
                </a>
                <a href="{{ route('alerts') }}" wire:navigate class="dk-navitem {{ $active === 'alerts' ? 'on' : '' }}">
                    <x-icon name="bell" /> Alertes
                    <x-alert-badge :count="$unreadAlerts" :size="18" />
                </a>
                <a href="{{ route('infos') }}" wire:navigate class="dk-navitem {{ $active === 'infos' ? 'on' : '' }}">
                    <x-icon name="info" /> Infos
                </a>

                @if ($isGuardian)
                    <div class="eyebrow">Parent garant</div>
                    <a href="{{ route('children') }}" wire:navigate class="dk-navitem {{ $active === 'children' ? 'on' : '' }}">
                        <x-icon name="users" /> Mes enfants
                    </a>
                @endif

                @if ($isCoach || $isAdmin)
                    <div class="eyebrow">Coach</div>
                    <a href="{{ route('sessions.create') }}" wire:navigate class="dk-navitem {{ $active === 'create' ? 'on' : '' }}">
                        <x-icon name="plus" /> Créer une séance
                    </a>
                @endif

                @if ($isAdmin)
                    <div class="eyebrow">Admin</div>
                    <a href="{{ route('admin.dashboard') }}" wire:navigate class="dk-navitem {{ $active === 'admin-dashboard' ? 'on' : '' }}">
                        <x-icon name="bar-chart" /> Dashboard
                    </a>
                    <a href="{{ route('admin.members') }}" wire:navigate class="dk-navitem {{ $active === 'members' ? 'on' : '' }}">
                        <x-icon name="users" /> Adhérents
                    </a>
                    <a href="{{ route('admin.templates') }}" wire:navigate class="dk-navitem {{ $active === 'templates' ? 'on' : '' }}">
                        <x-icon name="layers" /> Modèles
                    </a>
                    <a href="{{ route('admin.journal') }}" wire:navigate class="dk-navitem {{ $active === 'admin-journal' ? 'on' : '' }}">
                        <x-icon name="file-text" /> Journaux
                    </a>
                    <a href="{{ route('admin.outbox') }}" wire:navigate class="dk-navitem {{ $active === 'admin-outbox' ? 'on' : '' }}">
                        <x-icon name="send" /> Envois
                    </a>
                    <a href="{{ route('admin.infos') }}" wire:navigate class="dk-navitem {{ $active === 'admin-infos' ? 'on' : '' }}">
                        <x-icon name="info" /> Pages d'info
                    </a>
                    <a href="{{ route('admin.settings') }}" wire:navigate class="dk-navitem {{ $active === 'settings' ? 'on' : '' }}">
                        <x-icon name="settings" /> Paramètres
                    </a>
                @endif
            </nav>

            <hr class="divider">
            {{-- Pied de sidebar = entrée profil (le logout vit dans l'onglet Connexion, §4.1). --}}
            <a href="{{ route('profil') }}" wire:navigate class="dk-navitem dk-user {{ $active === 'profil' ? 'on' : '' }}">
                <x-avatar :name="trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? ''))" tint="tint-run" />
                <span class="dk-user-meta">
                    <span class="dk-user-name">{{ $u?->first_name }} {{ $u?->last_name }}</span>
                    <span class="meta">{{ implode(' · ', $u?->roles ?? []) }}</span>
                </span>
            </a>
        </aside>

        {{-- Contenu --}}
        <div class="app-content">
            <main class="app-main">
                @yield('content')
                {{ $slot ?? '' }}
            </main>
        </div>
    </div>

    {{-- Bottom nav mobile — HORS de .app-layout (enfant direct de <body>).
         Sur le planning, .app-layout est borné à height:100dvh ; une botnav position:fixed
         imbriquée dedans se voyait re-parentée à ce conteneur sur iOS PWA (elle se posait au bas
         de la boîte dvh, pas du viewport → blanc en bas au 1er paint, recalée seulement au touch
         SUR la barre). En la sortant du conteneur borné, elle est fixe par rapport au viewport
         réel quel que soit l'écran. Ses styles sont tous à base de classe (.botnav) → le
         déplacement DOM ne change rien au rendu desktop/mobile. --}}
    <nav class="botnav" aria-label="Navigation">
        <a href="{{ route('dashboard') }}" wire:navigate class="botnav-item {{ $active === 'home' ? 'on' : '' }}">
            <x-icon name="home" :size="22" /><span>Accueil</span>
        </a>
        <a href="{{ route('planning') }}" wire:navigate class="botnav-item {{ $active === 'planning' ? 'on' : '' }}">
            <x-icon name="calendar" :size="22" /><span>Planning</span>
        </a>
        {{-- 6 entrées au maximum (coach + parent garant) : « Parcours » est le libellé le plus long
             de la barre à font-size:10px. À valider visuellement ; repli = réduire le padding
             horizontal de .botnav-item, pas raccourcir le mot (doc J10 §9). --}}
        <a href="{{ route('gpx-routes.index') }}" wire:navigate class="botnav-item {{ $active === 'parcours' ? 'on' : '' }}">
            <x-icon name="route" :size="22" /><span>Parcours</span>
        </a>
        @if ($isCoach || $isAdmin)
            <a href="{{ route('sessions.create') }}" wire:navigate class="botnav-item {{ $active === 'create' ? 'on' : '' }}">
                <x-icon name="plus" :size="22" /><span>Créer</span>
            </a>
        @endif
        @if ($isGuardian)
            <a href="{{ route('children') }}" wire:navigate class="botnav-item {{ $active === 'children' ? 'on' : '' }}">
                <x-icon name="users" :size="22" /><span>Enfants</span>
            </a>
        @endif
        <a href="{{ route('profil') }}" wire:navigate class="botnav-item {{ $active === 'profil' ? 'on' : '' }}">
            <x-icon name="user" :size="22" /><span>Profil</span>
        </a>
    </nav>

    {{-- Rappel permanent de la démo (OS7) — hors .app-layout, comme la botnav : le conteneur est
         borné à 100dvh sur le planning mobile, un enfant position:fixed s'y verrait re-parenté. --}}
    <x-demo-badge />

    {{-- data-navigate-once : wire:navigate ré-exécute les scripts du body à chaque swap ;
         l'enregistrement du SW n'a de sens qu'au chargement initial. --}}
    <script data-navigate-once>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
        }
    </script>
</body>
</html>
