// Îlots JS de l'app (PWA Laravel/Blade). Le service worker est enregistré dans le layout.
// WYSIWYG TipTap (PRD §4.12.1) : périmètre figé, sortie markdown. La sanitisation fait foi côté PHP
// (App\Support\Markup) ; ce module ne porte que l'édition.
import './wysiwyg';
import './gpx';
// Retour contextuel du chevron de topbar : expose window.clubBack (retour historique + repli href).
import './back';
// Web Push : expose window.clubPush (souscription PushManager + sync serveur) — onglet Notifs (J8.6).
import './push';
