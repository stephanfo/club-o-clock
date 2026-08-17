<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\GpxRoute;
use App\Models\Location;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\GpxRouteService;
use App\Services\RegistrationService;
use App\Services\SessionNotificationService;
use App\Support\GpxStats;
use App\Support\Logging\AuditLogger;
use App\Support\Markup;
use App\Support\OpenRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

// Création / édition d'une Session standalone (PRD §4.7). Coach + admin (SessionPolicy).
#[Layout('layouts.app')]
#[Title('Séance — édition')]
class SessionForm extends Component
{
    use WithFileUploads;

    public ?Session $session = null;

    // Communs
    public string $kind = 'training';

    public string $title = '';

    public ?int $discipline_id = null;

    public string $start_at = '';

    public int $duration_min = 60;

    public ?int $location_id = null;

    public string $location_text = '';

    public ?int $capacity = null;

    /** @var array<int> */
    public array $category_ids = [];

    /** @var array<int> */
    public array $coach_ids = [];

    // training
    public ?int $quota_tag_id = null;

    public string $content_markdown = '';

    // competition
    public ?int $event_type_id = null;

    public string $distance = '';

    public string $external_url = '';

    // competition + club_event : album photos externe (§4.12.6)
    public string $photos_album_url = '';

    // club_event
    public string $agenda = '';

    // parcours OpenRunner (toutes séances, optionnel — §4.13.1)
    public string $route_openrunner_embed_url = '';

    public string $route_openrunner_public_url = '';

    // GPX (toutes séances, optionnel — §4.13.2). Fichier brut + métadonnées parsées CÔTÉ CLIENT.
    public $gpxFile = null;

    public ?array $gpxStats = null;

    /**
     * Parcours attaché (§4.20). Posé soit en sélectionnant une GpxRoute existante, soit par la
     * dropzone (qui crée la route via GpxRouteService puis pose l'id retourné).
     */
    public ?int $route_id = null;

    // Dialog de confirmation à la sauvegarde (§4.7) — n'apparaît qu'en édition d'une séance ayant
    // des inscrits actifs quand un champ structurant a changé.
    public bool $showSaveDialog = false;

    /** Case « envoi prioritaire » (§4.7) — pousse la notif tout de suite au lieu d'attendre le lot. */
    public bool $notify_priority = false;

    /** @var list<array{label:string,before:string,after:string}> diff affiché dans le dialog */
    public array $pendingChanges = [];

    public function mount(?Session $session = null): void
    {
        if ($session && $session->exists) {
            $this->authorize('update', $session);
            $this->session = $session;
            $this->fillFromSession($session);
        } else {
            $this->authorize('create', Session::class);
            $this->start_at = Carbon::now(ClubSettings::current()->timezone)->addDay()->setTime(18, 30)->format('Y-m-d\TH:i');
        }
    }

    private function fillFromSession(Session $s): void
    {
        $tz = ClubSettings::current()->timezone;
        $this->kind = $s->kind;
        $this->title = $s->title;
        $this->discipline_id = $s->discipline_id;
        $this->start_at = $s->start_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i');
        $this->duration_min = $s->duration_min;
        $this->location_id = $s->location_id;
        $this->location_text = $s->location_text ?? '';
        $this->capacity = $s->capacity;
        $this->category_ids = $s->categories->pluck('id')->all();
        $this->coach_ids = $s->coaches->pluck('id')->all();
        $this->quota_tag_id = $s->quota_tag_id;
        $this->content_markdown = $s->content_markdown ?? '';
        $this->event_type_id = $s->event_type_id;
        $this->distance = $s->distance ?? '';
        $this->external_url = $s->external_url ?? '';
        $this->photos_album_url = $s->photos_album_url ?? '';
        $this->agenda = $s->agenda ?? '';
        $this->route_openrunner_embed_url = $s->route_openrunner_embed_url ?? '';
        $this->route_openrunner_public_url = $s->route_openrunner_public_url ?? '';
        $this->route_id = $s->route_id;
        // Affichage des métadonnées en édition : elles vivent désormais en colonnes sur GpxRoute.
        $this->gpxStats = $s->gpxRoute ? $this->statsFromRoute($s->gpxRoute) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'kind' => ['required', 'in:training,competition,club_event'],
            'title' => ['required', 'string', 'max:255'],
            // discipline obligatoire training/competition, optionnelle club_event (§4.7).
            'discipline_id' => [$this->kind === 'club_event' ? 'nullable' : 'required', 'exists:disciplines,id'],
            'start_at' => ['required', 'date'],
            'duration_min' => ['required', 'integer', 'min:1', 'max:1440'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'category_ids' => ['array'],
            'category_ids.*' => ['exists:categories,id'],
            // Encadrants : doivent porter le rôle coach (cohérence avec CoachRegistrationService,
            // §4.11.6). Sinon un non-coach se retrouverait dans coaches[] (fausse les qualifs/stats).
            'coach_ids' => ['array', function ($attr, $value, $fail) {
                $ids = array_values(array_unique(array_map('intval', (array) $value)));
                if ($ids === []) {
                    return;
                }
                $coaches = User::whereIn('id', $ids)->whereJsonContains('roles', 'coach')->count();
                if ($coaches !== count($ids)) {
                    $fail('Un encadrant sélectionné n\'a pas le rôle coach.');
                }
            }],
            'coach_ids.*' => ['exists:users,id'],
            'quota_tag_id' => ['nullable', 'exists:quota_tags,id'],
            'content_markdown' => ['nullable', 'string'],
            // event_type obligatoire pour competition (§4.7).
            'event_type_id' => [$this->kind === 'competition' ? 'required' : 'nullable', 'exists:event_types,id'],
            'distance' => ['nullable', 'string', 'max:255'],
            // Schéma borné à http(s) : la règle `url` nue laisse passer `javascript:` (rendu en href).
            'external_url' => ['nullable', 'url:http,https', 'max:255'],
            'photos_album_url' => ['nullable', 'url:http,https', 'max:255'],
            'agenda' => ['nullable', 'string'],
            // Parcours OpenRunner : whitelist stricte côté serveur (§4.13.1).
            'route_openrunner_embed_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (filled($value) && ! OpenRunner::validEmbedUrl($value)) {
                    $fail('Lien d\'embed OpenRunner invalide — colle l\'URL `src` issue de la fonctionnalité Embed d\'OR Pro (elle contient /embed.html?code=…).');
                }
            }],
            'route_openrunner_public_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (filled($value) && ! OpenRunner::validPublicUrl($value)) {
                    $fail('Lien public OpenRunner invalide (https://www.openrunner.com/…).');
                }
            }],
            // GPX : ≤ 5 Mo, extension .gpx. Le fichier n'est PAS parsé serveur (§4.13.2).
            // Règles partagées avec GpxRouteForm : source unique, sinon les deux formulaires divergent.
            'gpxFile' => GpxStats::fileRules(),
            // Parcours sélectionné dans la bibliothèque (§4.20). Un parcours ARCHIVÉ est retiré de la
            // bibliothèque : il ne doit pas pouvoir être rattaché ici. `pickRoute()` le refuse déjà
            // (GpxRoute::active()), mais route_id est une propriété publique — sans cette règle, les
            // deux chemins d'entrée divergent et un POST direct rattache un parcours retiré.
            // Exception : celui DÉJÀ attaché à la séance en édition, qui a pu être archivé après coup.
            // L'interdire empêcherait de sauvegarder la séance sans toucher au parcours.
            'route_id' => ['nullable', Rule::exists('gpx_routes', 'id')->where(
                fn ($q) => $q->whereNull('archived_at')->when(
                    $this->session?->route_id,
                    fn ($sub, $id) => $sub->orWhere('id', $id),
                )
            )],
        ];
    }

    /** Bascule le type (cartes du proto) ; reset des champs spécifiques au kind quitté inutile (write côté save). */
    public function setKind(string $kind): void
    {
        if (in_array($kind, ['training', 'competition', 'club_event'], true)) {
            $this->kind = $kind;
        }
    }

    /** Chips toggle catégories ciblées (proto). */
    public function toggleCategory(int $id): void
    {
        $this->category_ids = in_array($id, $this->category_ids)
            ? array_values(array_diff($this->category_ids, [$id]))
            : [...$this->category_ids, $id];
    }

    /** Chips toggle encadrants (proto). */
    public function toggleCoach(int $id): void
    {
        $this->coach_ids = in_array($id, $this->coach_ids)
            ? array_values(array_diff($this->coach_ids, [$id]))
            : [...$this->coach_ids, $id];
    }

    /**
     * DÉTACHE le parcours de cette séance. Ne supprime rien : depuis §4.20 un parcours est partagé
     * entre N séances et vit dans la bibliothèque. Seul GpxRouteService::delete() (admin, parcours
     * orphelin) retire un fichier du disk.
     */
    public function removeGpx(): void
    {
        $this->gpxFile = null;
        $this->gpxStats = null;
        $this->route_id = null;
    }

    // ── Sélecteur de parcours existant (J10.B) ───────────────────────────────────────────────
    // Réutiliser une trace de la bibliothèque plutôt que de la ré-uploader : c'est la promesse
    // centrale de §4.20. L'upload direct reste disponible en dessous (avec sa bannière de doublon).

    /** Panneau de recherche ouvert. */
    public bool $showRoutePicker = false;

    /** Terme de recherche du sélecteur (jamais dans l'URL : c'est un état de formulaire). */
    public string $routeSearch = '';

    public function toggleRoutePicker(): void
    {
        $this->showRoutePicker = ! $this->showRoutePicker;
        $this->routeSearch = '';
    }

    /** Attache un parcours de la bibliothèque. Le fichier en attente, s'il y en a un, est abandonné. */
    public function pickRoute(int $routeId): void
    {
        $route = GpxRoute::active()->find($routeId);

        if (! $route) {
            session()->flash('warn', "Ce parcours n'est plus disponible.");

            return;
        }

        $this->gpxFile = null;
        $this->route_id = $route->id;
        $this->gpxStats = $this->statsFromRoute($route);
        $this->showRoutePicker = false;
        $this->routeSearch = '';
    }

    /**
     * Résultats du sélecteur : parcours actifs, discipline de la séance d'abord si elle est connue.
     *
     * @return Collection<int, GpxRoute>
     */
    public function routeCandidates(): Collection
    {
        return GpxRoute::active()
            ->when($this->routeSearch !== '', function ($q) {
                $term = '%'.$this->routeSearch.'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->with('discipline')
            // La discipline de la séance remonte en tête sans exclure les autres : un parcours vélo
            // peut légitimement servir à une séance « autre ».
            ->orderByRaw('discipline_id = ? DESC', [$this->discipline_id ?: 0])
            ->orderBy('name')
            ->take(12)
            ->get();
    }

    /**
     * Métadonnées d'affichage d'un parcours, dans la forme attendue par la dropzone (clés client).
     *
     * @return array<string, mixed>
     */
    private function statsFromRoute(GpxRoute $route): array
    {
        return array_filter([
            'name' => $route->gpx_original_name ?? $route->name,
            'sizeKo' => $route->gpx_size_ko,
            'distanceKm' => $route->distance_km,
            'dplus' => $route->dplus_m,
            'dmoins' => $route->dmoins_m,
            'altMin' => $route->alt_min_m,
            'altMax' => $route->alt_max_m,
            'pointCount' => $route->point_count,
            'durationMin' => $route->duration_min,
        ], fn ($v) => $v !== null);
    }

    public function save(SessionNotificationService $notifier)
    {
        $data = $this->validate();
        $isCreating = ! ($this->session && $this->session->exists);

        // En édition d'une séance avec inscrits actifs, un changement structurant OU de contenu (§4.7)
        // ouvre le dialog 3 choix (annuler / silence / notifier) au lieu de sauvegarder directement.
        if (! $isCreating && $this->hasActiveParticipants()) {
            $changes = [...$this->structuralChanges($data), ...$this->contentChanges($data)];
            if ($changes !== []) {
                $this->pendingChanges = $changes;
                $this->showSaveDialog = true;

                return null;
            }
        }

        // Création, ou édition sans inscrit / sans changement notifiable → sauvegarde silencieuse.
        $this->persist($data);

        // Une compétition / un événement club nouvellement créé est annoncé à sa catégorie cible
        // (§4.7, type event_created). Émis après persist, sur création uniquement.
        if ($isCreating && in_array($this->kind, ['competition', 'club_event'], true)) {
            $notifier->notifyEventCreated($this->session);
        }

        return $this->toShow();
    }

    /** (b) Sauvegarder en silence : applique les changements sans notifier (§4.7). */
    public function saveSilently()
    {
        $this->persist($this->validate());

        return $this->toShow();
    }

    /** (c) Sauvegarder et notifier les inscrits (§4.7) ; envoi prioritaire si la case est cochée. */
    public function saveAndNotify(SessionNotificationService $notifier)
    {
        $data = $this->validate();

        // Garde d'idempotence : on ne notifie que sur un changement réel d'une séance avec inscrits
        // (évite un envoi parasite hors dialog). Le type dépend de la nature du changement —
        // structurant prime (session_modified), sinon contenu (session_content). Évalué AVANT persist
        // (sinon la séance reflète déjà les nouvelles valeurs).
        $type = null;
        if ($this->session && $this->session->exists && $this->hasActiveParticipants()) {
            if ($this->structuralChanges($data) !== []) {
                $type = NotificationType::SessionModified;
            } elseif ($this->contentChanges($data) !== []) {
                $type = NotificationType::SessionContent;
            }
        }

        $this->persist($data);

        if ($type !== null) {
            // Après commit : fan-out aux `participating` (matrice/pause par destinataire).
            $notifier->notifyParticipants($this->session, $type, $this->notify_priority);
        }

        return $this->toShow();
    }

    /** (a) Annuler les changements : ferme le dialog, rien n'est persisté, le formulaire est préservé. */
    public function dismissSaveDialog(): void
    {
        $this->showSaveDialog = false;
        $this->pendingChanges = [];
        $this->notify_priority = false;
    }

    /** Inscrits actifs (`participating`) sur la séance éditée — cible des notifs d'événement séance. */
    private function hasActiveParticipants(): bool
    {
        return Registration::query()
            ->where('session_id', $this->session->id)
            ->where('status', 'participating')
            ->exists();
    }

    /**
     * Champs structurants modifiés (§4.7 : startAt, durée, lieu, capacité, tag quota, catégories).
     * Renvoie le diff avant→après affiché dans le dialog ; vide = aucun champ structurant changé.
     *
     * @param  array<string,mixed>  $data  données validées
     * @return list<array{label:string,before:string,after:string}>
     */
    private function structuralChanges(array $data): array
    {
        $s = $this->session;
        $tz = ClubSettings::current()->timezone;
        $changes = [];

        // Le formulaire est à la minute près : on compare à la minute pour éviter qu'un résidu de
        // secondes en base ne fasse passer une séance inchangée pour modifiée.
        $newStart = Carbon::parse($data['start_at'], $tz);
        $minute = fn (Carbon $d) => $d->copy()->setTimezone($tz)->format('Y-m-d H:i');
        if ($minute($s->start_at) !== $minute($newStart)) {
            $fmt = fn (Carbon $d) => $d->copy()->setTimezone($tz)->format('d/m H:i');
            $changes[] = ['label' => 'Date et heure', 'before' => $fmt($s->start_at), 'after' => $fmt($newStart)];
        }

        if ((int) $s->duration_min !== (int) $data['duration_min']) {
            $changes[] = ['label' => 'Durée', 'before' => "{$s->duration_min} min", 'after' => "{$data['duration_min']} min"];
        }

        $newLocText = $data['location_text'] ?: null;
        if ($s->location_id !== $data['location_id'] || ($s->location_text ?? null) !== $newLocText) {
            $changes[] = ['label' => 'Lieu',
                'before' => $this->locationLabel($s->location_id, $s->location_text),
                'after' => $this->locationLabel($data['location_id'], $newLocText)];
        }

        if ($s->capacity !== $data['capacity']) {
            $cap = fn (?int $c) => $c === null ? 'illimitée' : (string) $c;
            $changes[] = ['label' => 'Capacité', 'before' => $cap($s->capacity), 'after' => $cap($data['capacity'])];
        }

        $newTag = $data['kind'] === 'training' ? $data['quota_tag_id'] : null;
        if ($s->quota_tag_id !== $newTag) {
            $changes[] = ['label' => 'Tag quota',
                'before' => $this->quotaTagLabel($s->quota_tag_id),
                'after' => $this->quotaTagLabel($newTag)];
        }

        $oldCats = $s->categories->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $newCats = collect($data['category_ids'])->map(fn ($i) => (int) $i)->sort()->values()->all();
        if ($oldCats !== $newCats) {
            $n = fn (array $c) => count($c).' catégorie'.(count($c) > 1 ? 's' : '');
            $changes[] = ['label' => 'Catégories', 'before' => $n($oldCats), 'after' => $n($newCats)];
        }

        return $changes;
    }

    /**
     * Champs de contenu modifiés (§4.7 : texte de séance, parcours). Non structurants — mais peuvent
     * intéresser les inscrits, d'où le dialog 3 choix et le type session_content. La météo est
     * auto-récupérée hors de ce formulaire, donc absente d'ici.
     *
     * @param  array<string,mixed>  $data  données validées
     * @return list<array{label:string,before:string,after:string}>
     */
    private function contentChanges(array $data): array
    {
        $s = $this->session;
        $changes = [];

        // Texte de séance (training uniquement) — comparé après sanitisation (§4.12.1), comme au persist.
        $oldText = trim((string) $s->content_markdown);
        $newText = $data['kind'] === 'training' ? trim((string) Markup::clean($data['content_markdown'])) : '';
        if ($oldText !== $newText) {
            $changes[] = ['label' => 'Contenu', 'before' => $this->filledLabel($oldText !== ''), 'after' => $this->filledLabel($newText !== '')];
        }

        // Parcours (OpenRunner + GPX) sur toutes les séances : changement d'URL, nouveau fichier,
        // REMPLACEMENT du parcours ou retrait. Un remplacement A → B est un vrai changement de
        // contenu — l'adhérent choisit sa séance en partie sur le parcours — donc il notifie, et le
        // diff affiche les noms plutôt qu'un simple présent/absent.
        $newEmbed = $data['route_openrunner_embed_url'] ?: null;
        $newPublic = $data['route_openrunner_public_url'] ?: null;
        $newRouteId = $this->gpxFile !== null ? -1 : $this->route_id;   // -1 = nouveau fichier déposé
        $routeChanged = $newRouteId !== $s->route_id;

        if ($newEmbed !== $s->route_openrunner_embed_url || $newPublic !== $s->route_openrunner_public_url || $routeChanged) {
            $changes[] = [
                'label' => 'Parcours',
                'before' => $this->routeLabel($s->gpxRoute?->name, (bool) ($s->route_openrunner_embed_url || $s->route_openrunner_public_url)),
                'after' => $this->routeLabel(
                    $this->gpxFile !== null ? 'nouveau fichier' : $this->routeNameFor($this->route_id),
                    (bool) ($newEmbed || $newPublic),
                ),
            ];
        }

        return $changes;
    }

    private function filledLabel(bool $present): string
    {
        return $present ? 'présent' : 'aucun';
    }

    /** Libellé du parcours dans le diff : nom de la trace si connue, sinon présence d'un lien OpenRunner. */
    private function routeLabel(?string $routeName, bool $hasOpenRunner): string
    {
        if ($routeName !== null && $routeName !== '') {
            return $routeName;
        }

        return $hasOpenRunner ? 'lien OpenRunner' : 'aucun';
    }

    private function routeNameFor(?int $id): ?string
    {
        return $id === null ? null : GpxRoute::find($id)?->name;
    }

    /**
     * Nom du parcours créé depuis la dropzone d'une séance. Le coach ne saisit pas de nom ici :
     * on reprend celui du fichier (sans extension), à défaut le titre de la séance. Il reste
     * renommable depuis la bibliothèque.
     */
    private function routeNameFromUpload(): string
    {
        $fromFile = $this->gpxFile ? pathinfo((string) $this->gpxFile->getClientOriginalName(), PATHINFO_FILENAME) : '';
        $name = trim($fromFile) !== '' ? $fromFile : $this->title;

        return mb_substr(trim($name) !== '' ? $name : 'Parcours', 0, 160);
    }

    private function locationLabel(?int $id, ?string $text): string
    {
        if ($text) {
            return $text;
        }

        return $id !== null ? (Location::find($id)?->name ?? '—') : '—';
    }

    private function quotaTagLabel(?int $id): string
    {
        return $id !== null ? (QuotaTag::find($id)?->label ?? 'aucun') : 'aucun';
    }

    private function toShow()
    {
        session()->flash('status', 'Séance enregistrée.');

        return $this->redirect(route('sessions.show', $this->session), navigate: true);
    }

    /**
     * Persiste la séance (création ou mise à jour) + GPX + relations. Aucune notification ici :
     * l'émission éventuelle est décidée par l'appelant (save / saveSilently / saveAndNotify).
     *
     * @param  array<string,mixed>  $data  données validées
     */
    private function persist(array $data): void
    {
        // Défense en profondeur : ré-autorise à chaque persist (les actions Livewire ne re-passent
        // pas par mount()). Borne create/update aux coachs/admins (§4.7).
        if ($this->session && $this->session->exists) {
            $this->authorize('update', $this->session);
        } else {
            $this->authorize('create', Session::class);
        }

        $tz = ClubSettings::current()->timezone;

        // GPX (§4.20) : la dropzone ne stocke plus de fichier elle-même. Elle délègue à
        // GpxRouteService — point de passage unique partagé avec le formulaire de bibliothèque — et
        // ne garde que l'id du parcours créé. Aucun fichier n'est supprimé ici : un parcours est
        // partagé entre N séances, seul GpxRouteService::delete() purge le disk.
        $routeId = $this->route_id;
        if ($this->gpxFile) {
            $route = app(GpxRouteService::class)->createFromUpload(
                $this->gpxFile,
                ['name' => $this->routeNameFromUpload(), 'discipline_id' => $data['discipline_id']],
                $this->gpxStats,
                auth()->user(),
            );
            $routeId = $route->id;
        }

        $payload = [
            'kind' => $data['kind'],
            'title' => $data['title'],
            'discipline_id' => $data['discipline_id'],
            'start_at' => Carbon::parse($data['start_at'], $tz),
            'duration_min' => $data['duration_min'],
            'location_id' => $data['location_id'],
            'location_text' => $data['location_text'] ?: null,
            'capacity' => $data['capacity'],
            // Champs spécifiques : on n'écrit que ceux du kind, les autres restent null.
            'quota_tag_id' => $data['kind'] === 'training' ? $data['quota_tag_id'] : null,
            // WYSIWYG : sanitisation serveur faisant foi avant stockage (§4.12.1).
            'content_markdown' => $data['kind'] === 'training' ? Markup::clean($data['content_markdown']) : null,
            'event_type_id' => $data['kind'] === 'competition' ? $data['event_type_id'] : null,
            'distance' => $data['kind'] === 'competition' ? ($data['distance'] ?: null) : null,
            'external_url' => in_array($data['kind'], ['competition', 'club_event'], true) ? ($data['external_url'] ?: null) : null,
            'photos_album_url' => in_array($data['kind'], ['competition', 'club_event'], true) ? ($data['photos_album_url'] ?: null) : null,
            'agenda' => $data['kind'] === 'club_event' ? Markup::clean($data['agenda']) : null,
            // Parcours : optionnel sur toutes les séances (§4.13).
            'route_openrunner_embed_url' => $data['route_openrunner_embed_url'] ?: null,
            'route_openrunner_public_url' => $data['route_openrunner_public_url'] ?: null,
            'route_id' => $routeId,
        ];

        if ($this->session && $this->session->exists) {
            $oldCapacity = $this->session->capacity;
            $this->session->update($payload);
            AuditLogger::record('update_session', auth()->user(), ['session_id' => $this->session->id]);
            // Promotion FIFO si la capacité a augmenté (mécanisme A étendu, E2).
            $newCapacity = $data['capacity'];
            if ($newCapacity !== null && ($oldCapacity === null || $newCapacity > $oldCapacity)) {
                app(RegistrationService::class)->onCapacityIncreased($this->session);
            }
        } else {
            $payload['created_by'] = auth()->id();
            $this->session = Session::create($payload);
            AuditLogger::record('create_session', auth()->user(), ['session_id' => $this->session->id]);
        }

        $this->session->categories()->sync($data['category_ids']);
        $this->session->coaches()->sync($data['coach_ids']);
    }

    public function render()
    {
        return view('livewire.session-form', [
            'disciplines' => Discipline::whereNull('archived_at')->orderBy('sort_order')->get(),
            'eventTypes' => EventType::whereNull('archived_at')->orderBy('sort_order')->get(),
            'quotaTags' => QuotaTag::whereNull('archived_at')->orderBy('label')->get(),
            'categories' => Category::whereNull('archived_at')->orderBy('sort_order')->get(),
            'locations' => Location::where('is_archived', false)->orderBy('name')->get(),
            // qualifications eager-load : alimente l'agrégation temps réel du bloc « Qualifications
            // combinées » (QualificationDisplay::aggregate, §4.11.4) au fil de la sélection des coachs.
            'coaches' => User::whereJsonContains('roles', 'coach')->with('qualifications')->orderBy('last_name')->get(),
        ]);
    }
}
