<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Location;
use App\Models\Qualification;
use App\Models\QuotaTag;
use App\Services\CatalogueService;
use App\Services\GeocodingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

// Gestionnaire générique de catalogue (PRD §4.6, §4.17), porté de screen-catalogues.jsx.
// Un même composant gère les 6 catalogues (le $type vient de la route) : liste active + archivés,
// ajout, renommage inline, archivage soft, restauration, suppression dure si zéro référence.
// Garde-fou min-1-actif (disciplines, types d'épreuve). Admin uniquement (Gate manage-catalogues).
#[Layout('layouts.app')]
#[Title('Catalogues')]
class CatalogueManager extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-catalogues';
    }

    /** Type de catalogue : discipline|category|event_type|quota_tag|qualification|location. */
    public string $type;

    /** Ligne en cours d'édition (null = aucune), ou 'new' pour l'ajout. */
    public string|int|null $editingId = null;

    public bool $showArchived = false;

    /** Tampon du formulaire (champs selon $type). */
    public array $form = [];

    /** Suggestions d'adresses (autocomplétion lieu, §4.13.4) — peuplé au fil de la frappe. */
    public array $addressSuggestions = [];

    /** Définition d'affichage/champs par type. */
    private const TYPES = [
        'discipline' => ['model' => Discipline::class, 'singular' => 'Discipline', 'title' => 'Disciplines', 'fields' => ['label']],
        'category' => ['model' => Category::class, 'singular' => 'Catégorie d’âge', 'title' => 'Catégories d’âge', 'fields' => ['label', 'age_min', 'age_max']],
        'event_type' => ['model' => EventType::class, 'singular' => 'Type d’épreuve', 'title' => 'Types d’épreuve', 'fields' => ['label']],
        'quota_tag' => ['model' => QuotaTag::class, 'singular' => 'Tag de quota', 'title' => 'Tags de quota', 'fields' => ['label', 'code', 'max_per_week']],
        'qualification' => ['model' => Qualification::class, 'singular' => 'Qualification', 'title' => 'Qualifications', 'fields' => ['label', 'code']],
        'location' => ['model' => Location::class, 'singular' => 'Lieu', 'title' => 'Lieux', 'fields' => ['name', 'address', 'kind', 'latitude', 'longitude']],
    ];

    public function mount(string $type): void
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);
        $this->type = $type;
    }

    private function def(): array
    {
        return self::TYPES[$this->type];
    }

    private const ARCHIVE_COL = ['location' => 'is_archived'];

    private function archiveCol(): string
    {
        return self::ARCHIVE_COL[$this->type] ?? 'archived_at';
    }

    /** Règles de validation selon le type (champs présents). */
    protected function rules(): array
    {
        return match ($this->type) {
            'category' => [
                'form.label' => ['required', 'string', 'max:120'],
                'form.age_min' => ['required', 'integer', 'min:0', 'max:120'],
                'form.age_max' => ['required', 'integer', 'min:0', 'max:120', 'gte:form.age_min'],
            ],
            'quota_tag' => [
                'form.label' => ['required', 'string', 'max:120'],
                // code unique (la colonne l'impose en base) — ignore la ligne en cours d'édition.
                'form.code' => ['required', 'string', 'max:30', $this->uniqueRule('quota_tags', 'code')],
                'form.max_per_week' => ['required', 'integer', 'min:1', 'max:14'],
            ],
            'qualification' => [
                'form.label' => ['required', 'string', 'max:120'],
                'form.code' => ['nullable', 'string', 'max:30', $this->uniqueRule('qualifications', 'code')],
            ],
            'location' => [
                'form.name' => ['required', 'string', 'max:120'],
                'form.address' => ['nullable', 'string', 'max:255'],
                'form.kind' => ['nullable', 'string', 'max:40'],
                'form.latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'form.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ],
            default => ['form.label' => ['required', 'string', 'max:120']],
        };
    }

    /** Règle unique sur une colonne de catalogue, ignorant la ligne éditée (Rule::unique). */
    private function uniqueRule(string $table, string $column): Unique
    {
        $rule = Rule::unique($table, $column);

        return is_int($this->editingId) ? $rule->ignore($this->editingId) : $rule;
    }

    public function startAdd(): void
    {
        $this->editingId = 'new';
        $this->form = $this->blankForm();
        $this->addressSuggestions = [];
    }

    public function startEdit(int $id): void
    {
        $entity = $this->def()['model']::findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($this->def()['fields'])
            ->mapWithKeys(fn ($f) => [$f => $entity->{$f}])
            ->all();
        $this->addressSuggestions = [];
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->form = [];
        $this->addressSuggestions = [];
        $this->resetValidation();
    }

    /**
     * Hook Livewire : adresse modifiée → rafraîchit les suggestions (lieux uniquement, §4.13.4).
     * Ne touche pas lat/lng : l'utilisateur peut corriger librement avant de choisir une suggestion.
     */
    public function updatedFormAddress(?string $value, GeocodingService $geo): void
    {
        if ($this->type !== 'location') {
            return;
        }
        $this->addressSuggestions = $geo->search((string) $value);
    }

    /** Applique une suggestion : adresse + coordonnées auto-remplies, carte recentrée côté client. */
    public function pickSuggestion(int $i): void
    {
        $s = $this->addressSuggestions[$i] ?? null;
        // Sans coordonnées la suggestion est inexploitable (ex. entrée de cache d'un ancien format).
        if (! is_array($s) || ! isset($s['lat'], $s['lng'])) {
            return;
        }

        // Le clic remplit tous les champs (plus besoin de géocodage manuel) : nom proposé si vide,
        // adresse formatée, type déduit si vide, coordonnées exactes.
        if (trim((string) ($this->form['name'] ?? '')) === '' && ! empty($s['name'])) {
            $this->form['name'] = $s['name'];
        }
        if (trim((string) ($this->form['kind'] ?? '')) === '' && ! empty($s['type'])) {
            $this->form['kind'] = $s['type'];
        }
        $this->form['address'] = $s['address'] ?? '';
        $this->form['latitude'] = $s['lat'];
        $this->form['longitude'] = $s['lng'];
        $this->addressSuggestions = [];
        $this->dispatch('location-located', lat: $s['lat'], lng: $s['lng']);
    }

    /** Géocode l'adresse du lieu en cours d'édition (§4.13.4). Échec → saisie manuelle lat/lng. */
    public function geocode(GeocodingService $geo): void
    {
        $address = trim((string) ($this->form['address'] ?? ''));
        if ($address === '') {
            session()->flash('warn', 'Renseigne d\'abord une adresse à géocoder.');

            return;
        }

        $coords = $geo->geocode($address);
        if ($coords === null) {
            session()->flash('warn', 'Géocodage en échec — saisis la latitude et la longitude manuellement.');

            return;
        }

        $this->form['latitude'] = $coords['lat'];
        $this->form['longitude'] = $coords['lng'];
        $this->dispatch('location-located', lat: $coords['lat'], lng: $coords['lng']);
        session()->flash('status', 'Coordonnées renseignées par géocodage.');
    }

    public function saveRow(CatalogueService $service): void
    {
        $data = $this->validate()['form'];

        if ($this->editingId === 'new') {
            $service->create($this->type, $data, auth()->user());
            session()->flash('status', $this->def()['singular'].' ajouté·e.');
        } else {
            $entity = $this->def()['model']::findOrFail($this->editingId);
            $service->update($this->type, $entity, $data, auth()->user());
            session()->flash('status', 'Modifications enregistrées.');
        }

        $this->cancelEdit();
    }

    public function archive(int $id, CatalogueService $service): void
    {
        $this->runGuarded(fn () => $service->archive($this->type, $this->find($id), auth()->user()), 'Archivé·e.');
    }

    public function restore(int $id, CatalogueService $service): void
    {
        $this->runGuarded(fn () => $service->restore($this->type, $this->find($id), auth()->user()), 'Restauré·e.');
    }

    public function delete(int $id, CatalogueService $service): void
    {
        $this->runGuarded(fn () => $service->delete($this->type, $this->find($id), auth()->user()), 'Supprimé·e.');
    }

    private function find(int $id): Model
    {
        return $this->def()['model']::findOrFail($id);
    }

    /** Exécute une action de catalogue en traduisant les exceptions métier en messages. */
    private function runGuarded(callable $fn, string $ok): void
    {
        try {
            $fn();
            session()->flash('status', $ok);
        } catch (RuntimeException $e) {
            session()->flash('warn', match ($e->getMessage()) {
                CatalogueService::MUST_KEEP_ONE_ACTIVE => 'Impossible : il doit rester au moins une entrée active.',
                CatalogueService::STILL_REFERENCED => 'Référencé ailleurs — archive plutôt que supprimer.',
                default => $e->getMessage(),
            });
        }
    }

    private function blankForm(): array
    {
        $base = collect($this->def()['fields'])->mapWithKeys(fn ($f) => [$f => null])->all();
        if ($this->type === 'quota_tag') {
            $base['max_per_week'] = 2;
        }

        return $base;
    }

    public function render()
    {
        $col = $this->archiveCol();
        $model = $this->def()['model']::query();

        $active = (clone $model)->when($col === 'is_archived',
            fn ($q) => $q->where('is_archived', false),
            fn ($q) => $q->whereNull($col),
        )->orderBy($this->type === 'location' ? 'name' : 'label')->get();

        $archived = (clone $model)->when($col === 'is_archived',
            fn ($q) => $q->where('is_archived', true),
            fn ($q) => $q->whereNotNull($col),
        )->orderBy($this->type === 'location' ? 'name' : 'label')->get();

        return view('livewire.admin.catalogue-manager', [
            'def' => $this->def(),
            'active' => $active,
            'archived' => $archived,
        ]);
    }
}
