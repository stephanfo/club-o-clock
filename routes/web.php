<?php

use App\Http\Controllers\Auth\InvitationActivationController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\GpxRouteGpxController;
use App\Http\Controllers\GpxRouteTracesController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PushSubscriptionController;
use App\Livewire\Activation;
use App\Livewire\Admin\CatalogueManager;
use App\Livewire\Admin\ClubSettingsForm;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\InformationPageForm;
use App\Livewire\Admin\InformationPageList;
use App\Livewire\Admin\Journal;
use App\Livewire\Admin\MemberCreate;
use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\MemberShow;
use App\Livewire\Admin\Outbox;
use App\Livewire\Admin\TemplateForm;
use App\Livewire\Admin\TemplateList;
use App\Livewire\Alerts;
use App\Livewire\GpxRouteForm;
use App\Livewire\GpxRouteLibrary;
use App\Livewire\GpxRouteShow;
use App\Livewire\Home;
use App\Livewire\InformationPages;
use App\Livewire\ParentChildren;
use App\Livewire\Planning;
use App\Livewire\Profil;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

// Manifest PWA dynamique (plan open source OS2) — accessible sans authentification, reflète
// l'identité du club (ClubSettings) plutôt qu'un fichier statique figé.
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

// Mentions légales & confidentialité — publique (accessible sans connexion, RGPD données de mineurs).
Route::get('/mentions-legales', LegalController::class)->name('legal');

// --- Auth maison (par-dessus Fortify, cadrage §14.1) ---

// Magic link passwordless (PRD §4.1.1)
Route::middleware('guest')->group(function () {
    Route::get('magic-link', [MagicLinkController::class, 'request'])->name('magic-link.request');
    Route::post('magic-link', [MagicLinkController::class, 'send'])->name('magic-link.send');

    // ⚠️ ORDRE : ces routes littérales DOIVENT précéder magic-link/{token}, qui les capterait
    // sinon (« envoye » et « code » seraient pris pour des jetons).
    Route::get('magic-link/envoye', [MagicLinkController::class, 'sent'])->name('magic-link.sent');
    Route::get('magic-link/code', [MagicLinkController::class, 'codeForm'])->name('magic-link.code');
    Route::post('magic-link/code', [MagicLinkController::class, 'verifyCode'])->name('magic-link.code.verify');

    Route::get('magic-link/{token}', [MagicLinkController::class, 'consume'])->name('magic-link.consume');

    // Google OAuth via Socialite (PRD §4.1.1)
    Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

    // Activation d'un compte via son lien d'invitation (PRD §4.1.3, §4.2.1) — adhérent créé par le
    // bureau comme mineur autonomisé : même jeton, même page.
    Route::get('invitation/{token}', [InvitationActivationController::class, 'activate'])->name('invitation.activate');
});

// --- Espace connecté ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Home::class)->name('dashboard');

    // Écran d'accueil d'une activation d'invitation (§4.1.3) : choix de la méthode de connexion.
    // S'affiche une seule fois, sur le drapeau de session posé par InvitationActivationController.
    Route::get('/bienvenue', Activation::class)->name('activation');

    // Profil utilisateur — compte courant (J8.4, PRD §4.15.3/.4, §4.10, §4.1.1). Self only.
    Route::get('/profil', Profil::class)->name('profil');

    // Abonnements Web Push de l'appareil courant (J8.6, PRD §4.15). Activés depuis l'onglet Notifs.
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // Alertes / centre de notifications (PRD §4.15)
    Route::get('/alertes', Alerts::class)->name('alerts');

    // « Mes enfants » — parent garant uniquement (PRD §4.2). Garde 403 dans le composant.
    Route::get('/enfants', ParentChildren::class)->name('children');

    // Pages d'information (notes club, ajout post-cadrage). Consultation par tout membre —
    // la liste est filtrée par visibilité (scopeVisibleTo), pas de garde de rôle.
    Route::get('/infos', InformationPages::class)->name('infos');

    // Planning + séances (J1, PRD §4.7)
    Route::get('/planning', Planning::class)->name('planning');
    Route::get('/seances/creer', SessionForm::class)->name('sessions.create');
    Route::get('/seances/{session}', SessionShow::class)->name('sessions.show');
    Route::get('/seances/{session}/modifier', SessionForm::class)->name('sessions.edit');
    // Bibliothèque de parcours (J10, PRD §4.20). Le GPX appartient au parcours, plus à la séance.
    // Création/édition = coach + admin (garde fine dans GpxRoutePolicy, rejouée dans save()).
    // Consultation ouverte à tous les membres (GpxRoutePolicy::viewAny).
    Route::get('/parcours', GpxRouteLibrary::class)->name('gpx-routes.index');
    Route::get('/parcours/creer', GpxRouteForm::class)->name('gpx-routes.create');
    Route::get('/parcours/{gpxRoute}/modifier', GpxRouteForm::class)->name('gpx-routes.edit');
    // Téléchargement ouvert à tout membre connecté (§4.13.2).
    Route::get('/parcours/{gpxRoute}/gpx', [GpxRouteGpxController::class, 'download'])->name('gpx-routes.gpx');
    // Tracés simplifiés de la carte d'ensemble (J10.C bis). Segment PROPRE (`/parcours-traces`) et
    // non `/parcours/traces` : ce dernier entrerait en concurrence avec `/parcours/{gpxRoute}` et
    // dépendrait de l'ordre de déclaration, comme c'est déjà le cas pour `/parcours/creer`.
    Route::get('/parcours-traces', [GpxRouteTracesController::class, 'index'])->name('gpx-routes.traces');
    // Fiche parcours EN DERNIER : /parcours/creer doit être reconnu avant que {gpxRoute} ne le capte.
    Route::get('/parcours/{gpxRoute}', GpxRouteShow::class)->name('gpx-routes.show');

    // Modèles de génération — admin uniquement (J5, PRD §4.8). Garde fin dans SessionTemplatePolicy.
    Route::get('/admin/modeles', TemplateList::class)->name('admin.templates');
    Route::get('/admin/modeles/creer', TemplateForm::class)->name('admin.templates.create');
    Route::get('/admin/modeles/{template}/modifier', TemplateForm::class)->name('admin.templates.edit');

    // Dashboard statistiques bureau + export XLSX — admin uniquement (J6.6, PRD §4.16). Garde Gate.
    Route::get('/admin/dashboard', Dashboard::class)->name('admin.dashboard');

    // Journaux Audit/Activity + export XLSX — admin uniquement (J6.7, PRD §4.18). Garde Gate.
    Route::get('/admin/journaux', Journal::class)->name('admin.journal');

    // Gestion des envois sortants (outbox) — admin uniquement (J8.3, PRD §4.15.6). Garde Gate.
    Route::get('/admin/envois', Outbox::class)->name('admin.outbox');

    // Paramètres club + catalogues — admin uniquement (J6.1, PRD §4.17, §4.6). Garde Gate.
    Route::get('/admin/parametres', ClubSettingsForm::class)->name('admin.settings');
    Route::get('/admin/catalogues/{type}', CatalogueManager::class)->name('admin.catalogues');

    // Pages d'information — édition admin uniquement (Gate manage-information-pages).
    // « creer » avant « {page} » : route littérale prioritaire sur le param.
    Route::get('/admin/infos', InformationPageList::class)->name('admin.infos');
    Route::get('/admin/infos/creer', InformationPageForm::class)->name('admin.infos.create');
    Route::get('/admin/infos/{page}/modifier', InformationPageForm::class)->name('admin.infos.edit');

    // Adhérents — admin uniquement (J6.2, PRD §4.17.1, §4.1.3). Garde Gate manage-members.
    // « creer » avant « {user} » : route littérale prioritaire sur le param.
    Route::get('/admin/adherents', MemberList::class)->name('admin.members');
    Route::get('/admin/adherents/creer', MemberCreate::class)->name('admin.members.create');
    Route::get('/admin/adherents/{user}', MemberShow::class)->name('admin.members.show');
});
