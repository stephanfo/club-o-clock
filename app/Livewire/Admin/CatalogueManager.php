<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\AllureZone;
use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Location;
use App\Models\Qualification;
use App\Models\QuotaTag;
use App\Services\CatalogueService;
use App\Services\GeocodingService;
use App\Support\Allures\Referentiel;
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

    /** Type de catalogue : discipline|category|event_type|quota_tag|qualification|location|allure_zone. */
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
        'discipline' => ['model' => Discipline::class, 'singular' => 'Discipline', 'title' => 'Disciplines', 'fields' => ['label', 'referentiel']],
        'category' => ['model' => Category::class, 'singular' => 'Catégorie d’âge', 'title' => 'Catégories d’âge', 'fields' => ['label', 'age_min', 'age_max']],
        'event_type' => ['model' => EventType::class, 'singular' => 'Type d’épreuve', 'title' => 'Types d’épreuve', 'fields' => ['label']],
        'quota_tag' => ['model' => QuotaTag::class, 'singular' => 'Tag de quota', 'title' => 'Tags de quota', 'fields' => ['label', 'code', 'max_per_week']],
        'qualification' => ['model' => Qualification::class, 'singular' => 'Qualification', 'title' => 'Qualifications', 'fields' => ['label', 'code']],
        'location' => ['model' => Location::class, 'singular' => 'Lieu', 'title' => 'Lieux', 'fields' => ['name', 'address', 'kind', 'latitude', 'longitude']],
        'allure_zone' => ['model' => AllureZone::class, 'singular' => 'Zone d’allure', 'title' => 'Zones d’allure course', 'fields' => ['code', 'label', 'aliases', 'pct_min', 'pct_max']],
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
            'discipline' => [
                'form.label' => ['required', 'string', 'max:120'],
                'form.referentiel' => ['nullable', Rule::in(array_keys(Referentiel::options()))],
            ],
            'allure_zone' => [
                // Le code est ce que les consignes citent (#113) : court, sans espace, unique.
                'form.code' => ['required', 'string', 'max:12', 'regex:/^\S+$/u',
                    $this->uniqueRule('allure_zones', 'code')->where('referentiel', Referentiel::Course->value),
                    $this->codeLibreRule()],
                'form.label' => ['required', 'string', 'max:120'],
                // Alias reconnus comme le code : mêmes contraintes, et aucun déjà pris par une zone.
                'form.aliases' => ['nullable', 'string', 'max:60', $this->aliasesRule()],
                'form.pct_min' => ['required', 'integer', 'min:30', 'max:150'],
                'form.pct_max' => ['required', 'integer', 'min:30', 'max:150', 'gt:form.pct_min'],
            ],
            default => ['form.label' => ['required', 'string', 'max:120']],
        };
    }

    /**
     * Alias d'une zone : chacun court (12 caractères), distinct du code de la zone, et ni code ni
     * alias d'une autre zone active — sinon une consigne « SV2 » serait ambiguë.
     */
    private function aliasesRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $aliases = AllureZone::parseAliases((string) $value);
            $code = mb_strtolower(trim((string) ($this->form['code'] ?? '')));
            $pris = AllureZone::query()->active()
                ->where('referentiel', Referentiel::Course->value)
                ->when(is_int($this->editingId), fn ($q) => $q->whereKeyNot($this->editingId))
                ->get()
                ->flatMap(fn (AllureZone $z) => collect([$z->code, ...$z->aliasList()])->mapWithKeys(fn ($c) => [mb_strtolower($c) => $z->code]));
            foreach ($aliases as $alias) {
                if (mb_strlen($alias) > 12) {
                    $fail("Alias « {$alias} » trop long (12 caractères au plus).");
                } elseif (mb_strtolower($alias) === $code) {
                    $fail("« {$alias} » est déjà le code de la zone.");
                } elseif ($pris->has(mb_strtolower($alias))) {
                    $fail("« {$alias} » est déjà utilisé par la zone {$pris->get(mb_strtolower($alias))}.");
                }
            }
        };
    }

    /** Le code d'une zone ne doit pas être l'alias d'une autre zone active. */
    private function codeLibreRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $zone = AllureZone::query()->active()
                ->where('referentiel', Referentiel::Course->value)
                ->when(is_int($this->editingId), fn ($q) => $q->whereKeyNot($this->editingId))
                ->get()
                ->first(fn (AllureZone $z) => in_array(mb_strtolower((string) $value), array_map('mb_strtolower', $z->aliasList()), true));
            if ($zone !== null) {
                $fail("« {$value} » est déjà un alias de la zone {$zone->code}.");
            }
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

        if ($this->type === 'discipline') {
            $data['referentiel'] = ($data['referentiel'] ?? null) ?: null;
        }
        if ($this->type === 'allure_zone') {
            // V1 : un seul référentiel. Plages discontinues acceptées, chevauchement refusé.
            $data['referentiel'] = Referentiel::Course->value;
            $data['aliases'] = implode(', ', AllureZone::parseAliases($data['aliases'] ?? null)) ?: null;
            $clash = AllureZone::overlapping(Referentiel::Course, (int) $data['pct_min'], (int) $data['pct_max'],
                is_int($this->editingId) ? $this->editingId : null);
            if ($clash !== null) {
                $this->addError('form.pct_max', "Chevauche la zone {$clash->code} ({$clash->range()}).");

                return;
            }
        }

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
        $entity = $this->find($id);
        if ($entity instanceof AllureZone
            && ($clash = AllureZone::overlapping(Referentiel::Course, $entity->pct_min, $entity->pct_max, $entity->id)) !== null) {
            session()->flash('warn', "Impossible : la zone chevaucherait {$clash->code} ({$clash->range()}).");

            return;
        }
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

    private function orderColumn(): string
    {
        return match ($this->type) {
            'location' => 'name',
            'allure_zone' => 'pct_min',
            default => 'label',
        };
    }

    public function render()
    {
        $col = $this->archiveCol();
        $model = $this->def()['model']::query();

        $active = (clone $model)->when($col === 'is_archived',
            fn ($q) => $q->where('is_archived', false),
            fn ($q) => $q->whereNull($col),
        )->orderBy($this->orderColumn())->get();

        $archived = (clone $model)->when($col === 'is_archived',
            fn ($q) => $q->where('is_archived', true),
            fn ($q) => $q->whereNotNull($col),
        )->orderBy($this->orderColumn())->get();

        return view('livewire.admin.catalogue-manager', [
            'def' => $this->def(),
            'active' => $active,
            'archived' => $archived,
        ]);
    }
}
