<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Location;
use App\Models\Qualification;
use App\Models\QuotaTag;
use App\Services\AuthMethodService;
use App\Services\ClubBrandingService;
use App\Services\SeasonService;
use App\Support\ClubPalette;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

// Page « Paramètres du club » (PRD §4.17) — identité (nom, logo, palette), fuseau, durée du lien
// d'invitation, mois de bascule de saison. Personnalisation niveau intermédiaire (plan open
// source OS2) : color pickers primary/accent/info, générateur de déclinaisons App\Support\ClubPalette.
// Singleton ClubSettings. Catalogues gérés à part (CatalogueManager). Admin uniquement (Gate manage-club).
#[Layout('layouts.app')]
#[Title('Paramètres du club')]
class ClubSettingsForm extends Component
{
    use AuthorizesAdminGate;
    use WithFileUploads;

    protected function adminGate(): ?string
    {
        return 'manage-club';
    }

    public string $name = 'Club';

    /** Baseline de l'écran de connexion. Vide = celle du produit (ClubSettings::DEFAULT_TAGLINE). */
    public string $tagline = '';

    public string $timezone = 'Europe/Paris';

    public int $invitation_link_days = 30;

    public int $season_start_month = 9;

    public ?string $primary_color = null;

    public ?string $accent_color = null;

    public ?string $info_color = null;

    /** Fichier déposé, traité au submit par ClubBrandingService (vignettes générées côté serveur, GD requis). */
    public $logo = null;

    /** Icônes PWA en attente d'enregistrement, indexées par variante (cf. ClubSettings::PWA_ICONS). */
    public $icon_192 = null;

    public $icon_512 = null;

    public $icon_apple = null;

    // ── Mentions légales (§OS3) ──
    // Contenu propre à l'instance, saisi ici plutôt qu'écrit dans la vue publique : un club ne doit
    // jamais avoir à éditer le code source pour publier ses mentions (son fork divergerait).

    public ?string $legal_publisher = null;

    public ?string $legal_host = null;

    public ?string $legal_director = null;

    public ?string $legal_contact_email = null;

    public ?string $legal_source_url = null;

    public ?string $legal_mail_provider = null;

    // ── Interrupteurs d'instance (§4.17) ──
    // Persistés au clic (pas de submit) : ce sont des bascules, pas des champs de formulaire, et
    // <x-toggle> est un <button> piloté serveur — incompatible wire:model. Ils ne figurent donc ni
    // dans rules() ni dans le $data de save().

    public bool $notif_push_enabled = true;

    public bool $notif_email_enabled = true;

    public bool $auth_magic_link_enabled = true;

    public bool $auth_google_enabled = true;

    /** Fuseaux proposés (V1 FR — liste courte, défaut Europe/Paris). */
    public array $timezones = ['Europe/Paris', 'Europe/Brussels', 'Europe/Luxembourg', 'Indian/Reunion', 'America/Guadeloupe', 'UTC'];

    /** Mois proposés pour la bascule de saison (1=janvier … 12=décembre). */
    public array $months = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    // Bascule de saison (§4.4) : modale suspension de masse, double validation + motif.
    public bool $showBascule = false;

    public bool $basculeCheck1 = false;

    public bool $basculeCheck2 = false;

    public string $basculeMotif = '';

    // Nouvelle année sportive (§4.5) : modale de recalcul des catégories.
    public bool $showNouvelleAnnee = false;

    public function mount(): void
    {
        $s = ClubSettings::current();
        $this->name = $s->name;
        // Laissé VIDE si non personnalisé (contrairement aux color pickers ci-dessous) : un champ
        // texte vide se lit sans ambiguïté, et le placeholder de la vue montre le défaut appliqué.
        $this->tagline = $s->tagline ?? '';
        $this->timezone = $s->timezone;
        $this->invitation_link_days = $s->invitation_link_days;
        $this->season_start_month = $s->season_start_month ?: 9;
        // Affichage seulement : tant que le club n'a rien personnalisé, on préremplit les color
        // pickers avec les couleurs de démarrage réellement actives (sinon un <input type="color">
        // vide affiche noir, donnant l'impression trompeuse que la palette active est noire). Le
        // submit n'écrit en base que si l'admin modifie effectivement une valeur (cf. save()).
        $this->primary_color = $s->primary_color ?: ClubPalette::DEFAULTS['primary_color'];
        $this->accent_color = $s->accent_color ?: ClubPalette::DEFAULTS['accent_color'];
        $this->info_color = $s->info_color ?: ClubPalette::DEFAULTS['info_color'];

        foreach (ClubSettings::LEGAL_FIELDS as $field) {
            $this->{$field} = $s->{$field};
        }

        $this->notif_push_enabled = $s->notif_push_enabled;
        $this->notif_email_enabled = $s->notif_email_enabled;
        $this->auth_magic_link_enabled = $s->auth_magic_link_enabled;
        $this->auth_google_enabled = $s->auth_google_enabled;
    }

    /**
     * Bascule un canal de notification pour tout le club (§4.17). Aucune garde : couper les deux est
     * permis — les emails d'authentification (lien de connexion) passent hors outbox et continuent.
     */
    public function toggleChannel(string $channel): void
    {
        $attribute = match ($channel) {
            'push' => 'notif_push_enabled',
            'email' => 'notif_email_enabled',
            default => null, // clé venue du client : on ignore au lieu d'écrire n'importe quoi
        };

        if ($attribute === null) {
            return;
        }

        $this->{$attribute} = ! $this->{$attribute};
        ClubSettings::current()->update([$attribute => $this->{$attribute}]);
        AuditLogger::record('club_settings_updated', auth()->user(), [
            'motif' => $channel.'='.($this->{$attribute} ? 'on' : 'off'),
        ]);

        session()->flash('status', $this->{$attribute}
            ? 'Canal réactivé — les notifications repartent.'
            : 'Canal désactivé — plus aucune notification ne partira par ce canal.');
    }

    /**
     * Bascule un moyen de connexion (§4.17). REFUSE la coupure si des comptes actifs n'auraient
     * plus aucun accès : les comptes créés par invitation ou activation de tutelle (§4.2.1) sont
     * passwordless, le lien magique est leur seule porte. Garantit l'invariant §4.1.2.
     */
    public function toggleAuthMethod(string $method, AuthMethodService $authMethods): void
    {
        $attribute = AuthMethodService::SWITCHABLE[$method] ?? null;

        if ($attribute === null) {
            return;
        }

        $next = ! $this->{$attribute};

        if (! $next) {
            $locked = $authMethods->lockedOutByDisabling($method);

            if ($locked->isNotEmpty()) {
                session()->flash('warn', $locked->count().' compte(s) actif(s) n\'auraient plus aucun moyen de se connecter : '
                    .'donne-leur un mot de passe ou une autre méthode avant de couper celle-ci.');

                return;
            }
        }

        $this->{$attribute} = $next;
        ClubSettings::current()->update([$attribute => $next]);
        AuditLogger::record('club_settings_updated', auth()->user(), [
            'motif' => $method.'='.($next ? 'on' : 'off'),
        ]);

        session()->flash('status', $next
            ? 'Moyen de connexion réactivé.'
            : 'Moyen de connexion désactivé — il disparaît de l\'écran de connexion.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'invitation_link_days' => ['required', 'integer', 'min:1', 'max:365'],
            'season_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'primary_color' => ['nullable', 'regex:'.ClubPalette::HEX_PATTERN],
            'accent_color' => ['nullable', 'regex:'.ClubPalette::HEX_PATTERN],
            'info_color' => ['nullable', 'regex:'.ClubPalette::HEX_PATTERN],
            // Liste blanche explicite en plus de `image` : cette dernière exclut bien le SVG en
            // Laravel 13, mais implicitement (validateImage ne l'autorise que sur `allow_svg`).
            // Or le logo atterrit sur le disque `public`, servi same-origin : un SVG y serait un
            // XSS stocké. On écrit donc les formats admis, plutôt que de dépendre du défaut d'une
            // règle du framework — et on exclut au passage gif/bmp, que `image` accepte et dont on
            // n'a aucun usage ici.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // PNG SEUL pour les icônes, et dimensions EXACTES : le manifest les déclare en
            // image/png, et une icône hors format casse l'installation PWA sans erreur visible
            // (cadrage §7.16). Mieux vaut refuser au dépôt que livrer une PWA cassée en silence.
            'icon_192' => ['nullable', 'image', 'mimes:png', 'dimensions:width=192,height=192', 'max:1024'],
            'icon_512' => ['nullable', 'image', 'mimes:png', 'dimensions:width=512,height=512', 'max:1024'],
            'icon_apple' => ['nullable', 'image', 'mimes:png', 'dimensions:width=180,height=180', 'max:1024'],
            'legal_publisher' => ['nullable', 'string', 'max:500'],
            'legal_host' => ['nullable', 'string', 'max:500'],
            'legal_director' => ['nullable', 'string', 'max:255'],
            'legal_contact_email' => ['nullable', 'email', 'max:255'],
            // url:http,https explicite : sans schéma, la règle `url` accepterait javascript: —
            // or cette valeur est rendue en href sur une page PUBLIQUE.
            'legal_source_url' => ['nullable', 'url:http,https', 'max:255'],
            'legal_mail_provider' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Valide le fichier DÈS le dépôt, sans attendre le submit : la vue appelle temporaryUrl() pour
     * l'aperçu au re-render qui suit l'upload, et Livewire lève FileNotPreviewableException (500)
     * sur un type non affichable. `accept="image/*"` ne protège pas — la boîte de dialogue système
     * laisse choisir « Tous les fichiers ». Un fichier invalide est rejeté et la propriété vidée,
     * pour que la vue ne tente jamais l'aperçu.
     */
    public function updatedLogo(): void
    {
        try {
            $this->validateOnly('logo');
        } catch (ValidationException $e) {
            $this->reset('logo');
            throw $e;
        }
    }

    /** Mêmes contraintes d'aperçu que updatedLogo() : on valide au dépôt, sinon temporaryUrl() 500. */
    public function updatedIcon192(): void
    {
        $this->validateIconOrReset('icon_192');
    }

    public function updatedIcon512(): void
    {
        $this->validateIconOrReset('icon_512');
    }

    public function updatedIconApple(): void
    {
        $this->validateIconOrReset('icon_apple');
    }

    private function validateIconOrReset(string $property): void
    {
        try {
            $this->validateOnly($property);
        } catch (ValidationException $e) {
            $this->reset($property);
            throw $e;
        }
    }

    /**
     * Rétablit le jeu d'icônes livré avec l'application (PRD §4.17).
     *
     * Anodin et réversible (le club peut re-téléverser) : `wire:confirm` suffit, pas de x-dialog.
     */
    public function resetPwaIcons(ClubBrandingService $branding): void
    {
        $branding->resetPwaIcons(ClubSettings::current(), auth()->user());

        $this->reset('icon_192', 'icon_512', 'icon_apple');

        session()->flash('status', 'Icônes par défaut rétablies.');
    }

    public function save(ClubBrandingService $branding): void
    {
        $data = $this->validate();
        $logo = $data['logo'] ?? null;
        unset($data['logo']);

        // Les icônes ne sont pas des colonnes éditables directement : le service écrit lui-même le
        // chemin après validation GD et ré-encodage. On les sort donc du $data d'update().
        $icons = [];
        foreach (array_keys(ClubSettings::PWA_ICONS) as $variant) {
            if (! empty($data[$variant])) {
                $icons[$variant] = $data[$variant];
            }
            unset($data[$variant]);
        }

        // Les color pickers sont préremplis en affichage avec les couleurs de démarrage (mount()) —
        // un <input type="color"> ne peut pas rester vide. Si l'admin n'a pas dévié du défaut, on
        // écrit null (pas la valeur par défaut) pour que ClubPalette::overrideCss() continue de ne
        // rien injecter : la distinction « personnalisé vs palette neutre » reste possible.
        foreach (ClubPalette::DEFAULTS as $attribute => $default) {
            if (($data[$attribute] ?? null) === $default) {
                $data[$attribute] = null;
            }
        }

        // Même principe pour la baseline : champ vidé (ou remis mot pour mot sur celle du produit)
        // → null, c'est-à-dire « non personnalisé ». Le club récupère ainsi le défaut, et celui-ci
        // pourra évoluer sans figer une copie en base.
        $tagline = trim($data['tagline'] ?? '');
        $data['tagline'] = ($tagline === '' || $tagline === ClubSettings::DEFAULT_TAGLINE) ? null : $tagline;

        // Champ vidé → null, et non chaîne vide : c'est ce que legalNoticeIncomplete() teste pour
        // afficher l'avertissement « mentions non complétées » sur la page publique.
        foreach (ClubSettings::LEGAL_FIELDS as $field) {
            $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }

        $settings = ClubSettings::current();
        $settings->update($data);

        if ($logo) {
            $branding->replaceLogo($settings, $logo, auth()->user());
        }

        foreach ($icons as $variant => $file) {
            $branding->replacePwaIcon($settings, $variant, $file, auth()->user());
        }

        AuditLogger::record('club_settings_updated', auth()->user(), []);

        session()->flash('status', 'Paramètres du club enregistrés.');
        $this->reset('logo', 'icon_192', 'icon_512', 'icon_apple');
    }

    // ── Actions de saison (§4.4 suspension de masse · §4.5 recalcul catégories) ──
    // Centralisées ici (PRD §4.17) ; la logique métier vit dans SeasonService (transactionnelle,
    // auditée, testée). La bascule (destructive) exige une double validation gardée côté serveur.

    public function openBascule(): void
    {
        $this->reset('basculeCheck1', 'basculeCheck2', 'basculeMotif');
        $this->showBascule = true;
    }

    /** §4.4 — suspension de masse. Exige les deux cases de double validation (UI + serveur). */
    public function deactivateAllAthletes(SeasonService $season): void
    {
        if (! $this->basculeCheck1 || ! $this->basculeCheck2) {
            $this->addError('bascule', 'Coche les deux confirmations pour suspendre la saison.');

            return;
        }

        $result = $season->deactivateAllAthletes(auth()->user(), $this->basculeMotif ?: null);
        $this->reset('showBascule', 'basculeCheck1', 'basculeCheck2', 'basculeMotif');
        session()->flash('status', "Saison basculée : {$result['accounts']} comptes suspendus, {$result['registrations']} inscriptions annulées.");
    }

    /** §4.5 — démarrage de la nouvelle année sportive (recalcul catégories + purge surclassements). */
    public function startNewSeason(SeasonService $season): void
    {
        $result = $season->startNewSeason(auth()->user());
        $this->showNouvelleAnnee = false;
        session()->flash('status', "Nouvelle année sportive démarrée : {$result['recalculated']} catégories recalculées, {$result['surclassements_removed']} surclassements effacés.");
    }

    /**
     * Libellé court de l'année sportive (« sept → août »), dérivé du mois de bascule choisi.
     *
     * Calculé plutôt qu'écrit en dur : l'écran propose le réglage trois champs plus haut, un
     * libellé figé y contredirait le choix que l'admin vient de faire. On lit la propriété du
     * formulaire (et non la base) pour que l'aperçu suive la sélection avant enregistrement.
     */
    public function getSeasonLabelProperty(): string
    {
        $start = Carbon::create(2000, $this->season_start_month ?: 9, 1);

        return $start->locale('fr')->isoFormat('MMM').' → '.$start->copy()->subMonth()->locale('fr')->isoFormat('MMM');
    }

    /** Vrai dès qu'au moins une icône PWA a été téléversée par le club. */
    private function pwaIconsCustomised(): bool
    {
        $settings = ClubSettings::current();

        foreach (ClubSettings::PWA_ICONS as [$column, $fallback]) {
            if ($settings->{$column}) {
                return true;
            }
        }

        return false;
    }

    /** Comptes actifs (non archivés) par catalogue — affichés en regard du hub (proto « · N »). */
    private function catalogueCounts(): array
    {
        return [
            'category' => Category::whereNull('archived_at')->count(),
            'quota_tag' => QuotaTag::whereNull('archived_at')->count(),
            'qualification' => Qualification::whereNull('archived_at')->count(),
            'discipline' => Discipline::whereNull('archived_at')->count(),
            'event_type' => EventType::whereNull('archived_at')->count(),
            'location' => Location::where('is_archived', false)->count(),
        ];
    }

    public function render(SeasonService $seasons, AuthMethodService $authMethods)
    {
        return view('livewire.admin.club-settings-form', [
            'counts' => $this->catalogueCounts(),
            'logoPath' => ClubSettings::current()->logo_path,
            // Le bouton « rétablir » ne s'affiche que s'il y a quelque chose à rétablir : proposer
            // de revenir au défaut quand on y est déjà n'a pas de sens et inquiète inutilement.
            'pwaIconsCustomised' => $this->pwaIconsCustomised(),
            // Compteurs d'impact pour les modales de saison (calculés seulement à l'ouverture).
            'impact' => ($this->showBascule || $this->showNouvelleAnnee) ? $seasons->impactCounters() : null,
            // Avertissement en amont du clic : combien de comptes seraient verrouillés dehors si
            // l'admin coupait le lien magique. Calculé seulement tant qu'il est ouvert (sinon la
            // question ne se pose plus) — même paresse que les compteurs d'impact ci-dessus.
            'magicLinkOnly' => $this->auth_magic_link_enabled
                ? $authMethods->lockedOutByDisabling('magic_link')->count()
                : 0,
            'googleMisconfigured' => $authMethods->googleMisconfigured(),
        ]);
    }
}
