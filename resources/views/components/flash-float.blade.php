{{-- Flash flottant auto-masqué : feedback d'action posé en bannière fixe haut-centre,
     disparaît après 3,6 s (évite qu'une action échoue ou réussisse en silence).
     Rendu UNE fois par le layout — deux clés sémantiques (revue UX 2026-07-11) :
     flash('status', …) = succès/info (vert) · flash('warn', …) = refus/erreur (orange). --}}
@if (session('status') || session('warn'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3600)" x-transition x-cloak
         style="position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:80;max-width:92vw">
        <x-banner :kind="session('warn') ? 'warn' : 'green'" style="margin:0;box-shadow:var(--shadow-lg)">{{ session('warn') ?? session('status') }}</x-banner>
    </div>
@endif
