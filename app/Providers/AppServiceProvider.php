<?php

namespace App\Providers;

use App\Models\User;
use App\Notifications\Push\MinishlinkWebPushSender;
use App\Notifications\Push\WebPushSender;
use App\Support\DemoMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Envoi Web Push réel (J8.6) ; les tests rebindent une fake sans réseau ni clés VAPID.
        $this->app->bind(WebPushSender::class, MinishlinkWebPushSender::class);

        // Instance de démonstration (OS7) : les garde-fous d'envoi sont imposés ici, avant
        // que quoi que ce soit ne résolve `mail.default` ou un canal de notification. Une
        // démo dont les identifiants admin sont publics ne doit rien pouvoir envoyer.
        if (DemoMode::enabled()) {
            DemoMode::enforce($this->app['config']);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mode strict Eloquent hors prod : tout lazy-load (N+1) ou attribut non fillable
        // silencieusement écarté explose en dev/tests au lieu de passer inaperçu — la suite
        // de tests sert ainsi de détecteur de N+1. preventAccessingMissingAttributes est
        // volontairement exclu : il jette sur les instances factory en mémoire (actingAs)
        // qui n'ont pas relu les colonnes par défaut — faux positifs de test, pas des bugs.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Derrière le proxy TLS d'OVH mutualisé, la terminaison HTTPS se fait en
        // amont : selon l'offre, X-Forwarded-Proto n'est pas toujours transmis,
        // et Livewire génère alors son asset (livewire.min.js) en http:// depuis
        // une page https:// → bloqué en mixed content côté navigateur. Dès que
        // APP_URL est en https, on force le scheme applicatif pour que TOUTES les
        // URLs générées (assets Livewire inclus) sortent en https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Back-office (PRD §4.17, §4.6) : paramètres club + catalogues = admin uniquement.
        // Gates plutôt que 7 policies de modèle (les catalogues partagent la même règle).
        Gate::define('manage-club', fn (User $user) => $user->isAdmin());
        Gate::define('manage-catalogues', fn (User $user) => $user->isAdmin());

        // Adhérents (PRD §4.17.1, §4.1.3) : liste, fiche, création = admin uniquement (J6.2).
        Gate::define('manage-members', fn (User $user) => $user->isAdmin());

        // Dashboard statistiques bureau + export XLSX (PRD §4.16) : admin uniquement (J6.6).
        Gate::define('view-dashboard', fn (User $user) => $user->isAdmin());

        // Journaux Audit/Activity + export XLSX (PRD §4.18.5) : admin uniquement (J6.7).
        // Les coachs gardent les badges in-context sur les fiches séance, pas la page.
        Gate::define('view-journal', fn (User $user) => $user->isAdmin());

        // Gestion des envois sortants — outbox (PRD §4.15.6) : admin uniquement (J8.3).
        Gate::define('manage-outbox', fn (User $user) => $user->isAdmin());

        // Pages d'information (notes club, ajout post-cadrage) : édition = admin uniquement.
        // La consultation n'est pas gardée ici (tout membre), elle est filtrée par visibilité.
        Gate::define('manage-information-pages', fn (User $user) => $user->isAdmin());
    }
}
