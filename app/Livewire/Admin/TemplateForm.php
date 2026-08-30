<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\Discipline;
use App\Models\Location;
use App\Models\QuotaTag;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\TemplateGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Création / édition d'un modèle de génération (PRD §4.8), porté de screen-modele-create.jsx.
// À l'enregistrement d'une CRÉATION, le système génère immédiatement N Session indépendantes.
// L'édition d'un modèle existant met à jour le modèle SANS re-propager (pas de lien comportemental).
// Admin uniquement (SessionTemplatePolicy).
#[Layout('layouts.app')]
#[Title('Modèle — édition')]
class TemplateForm extends Component
{
    public ?SessionTemplate $template = null;

    public string $label = '';

    public string $kind = 'training';

    public ?int $discipline_id = null;

    /** Jour ISO 1..7 (lundi..dimanche). */
    public int $day_of_week = 1;

    public string $start_time_of_day = '19:00';

    public int $duration_min = 90;

    public ?int $location_id = null;

    public string $location_text = '';

    public ?int $capacity = null;

    public ?int $quota_tag_id = null;

    /** @var array<int> */
    public array $category_ids = [];

    /** @var array<int> */
    public array $coach_ids = [];

    public string $generation_start_date = '';

    public string $generation_end_date = '';

    public function mount(?SessionTemplate $template = null): void
    {
        if ($template && $template->exists) {
            $this->authorize('update', $template);
            $this->template = $template;
            $this->fillFromTemplate($template);
        } else {
            $this->authorize('create', SessionTemplate::class);
            // Préremplit sur la saison sportive en cours (sept → juin).
            $this->generation_start_date = Carbon::now()->toDateString();
            $this->generation_end_date = Carbon::now()->addMonths(6)->toDateString();
        }
    }

    private function fillFromTemplate(SessionTemplate $t): void
    {
        $this->label = $t->label;
        $this->kind = $t->kind;
        $this->discipline_id = $t->discipline_id;
        $this->day_of_week = $t->day_of_week;
        $this->start_time_of_day = substr((string) $t->start_time_of_day, 0, 5);
        $this->duration_min = $t->duration_min;
        $this->location_id = $t->location_id;
        $this->location_text = $t->location_text ?? '';
        $this->capacity = $t->capacity;
        $this->quota_tag_id = $t->quota_tag_id;
        $this->category_ids = $t->categories->pluck('id')->all();
        $this->coach_ids = $t->defaultCoaches->pluck('id')->all();
        $this->generation_start_date = $t->generation_start_date->toDateString();
        $this->generation_end_date = $t->generation_end_date->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:training,competition,club_event'],
            // Discipline : entraînement UNIQUEMENT, comme sur la séance (§4.7).
            'discipline_id' => [$this->kind === 'training' ? 'required' : 'nullable', 'exists:disciplines,id'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time_of_day' => ['required', 'date_format:H:i'],
            'duration_min' => ['required', 'integer', 'min:1', 'max:1440'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'quota_tag_id' => ['nullable', 'exists:quota_tags,id'],
            'category_ids' => ['array'],
            'category_ids.*' => ['exists:categories,id'],
            'coach_ids' => ['array'],
            'coach_ids.*' => ['exists:users,id'],
            'generation_start_date' => ['required', 'date'],
            // generationEndDate OBLIGATOIRE et ≥ début — pas de récurrence infinie (§4.8).
            'generation_end_date' => ['required', 'date', 'after_or_equal:generation_start_date'],
        ];
    }

    public function setKind(string $kind): void
    {
        if (in_array($kind, ['training', 'competition', 'club_event'], true)) {
            $this->kind = $kind;
        }
    }

    public function setDay(int $day): void
    {
        if ($day >= 1 && $day <= 7) {
            $this->day_of_week = $day;
        }
    }

    public function toggleCategory(int $id): void
    {
        $this->category_ids = in_array($id, $this->category_ids)
            ? array_values(array_diff($this->category_ids, [$id]))
            : [...$this->category_ids, $id];
    }

    public function toggleCoach(int $id): void
    {
        $this->coach_ids = in_array($id, $this->coach_ids)
            ? array_values(array_diff($this->coach_ids, [$id]))
            : [...$this->coach_ids, $id];
    }

    /** Aperçu live : nombre de séances générées sur la plage saisie (§4.8). */
    public function getOccurrenceCountProperty(): int
    {
        return $this->previewOccurrences()->count();
    }

    /**
     * Nombre d'occurrences déjà passées dans la plage — séances générées dans le passé, inutiles
     * (inscriptions bloquées dès start_at). Avertissement non bloquant : l'admin maîtrise.
     */
    public function getPastCountProperty(): int
    {
        $today = Carbon::today();

        return $this->previewOccurrences()->filter(fn (Carbon $d) => $d->lt($today))->count();
    }

    /** @return Collection<int, Carbon> occurrences de la plage saisie. */
    private function previewOccurrences()
    {
        if (! $this->generation_start_date || ! $this->generation_end_date) {
            return collect();
        }

        $probe = new SessionTemplate(['day_of_week' => $this->day_of_week]);

        return app(TemplateGenerationService::class)
            ->occurrences($probe, Carbon::parse($this->generation_start_date), Carbon::parse($this->generation_end_date));
    }

    public function save(TemplateGenerationService $service)
    {
        $data = $this->validate();

        $payload = [
            'label' => $data['label'],
            'kind' => $data['kind'],
            'discipline_id' => $data['kind'] === 'training' ? $data['discipline_id'] : null,
            'day_of_week' => $data['day_of_week'],
            'start_time_of_day' => $data['start_time_of_day'],
            'duration_min' => $data['duration_min'],
            'location_id' => $data['location_id'],
            'location_text' => $data['location_text'] ?: null,
            'capacity' => $data['capacity'],
            'quota_tag_id' => $data['kind'] === 'training' ? $data['quota_tag_id'] : null,
            'generation_start_date' => $data['generation_start_date'],
            'generation_end_date' => $data['generation_end_date'],
        ];

        if ($this->template && $this->template->exists) {
            // Édition : met à jour le modèle, NE re-propage PAS aux séances déjà générées (§4.8).
            $this->template->update($payload);
            $this->template->categories()->sync($data['category_ids']);
            $this->template->defaultCoaches()->sync($data['coach_ids']);
            session()->flash('status', 'Modèle enregistré.');
        } else {
            // Création : persiste le modèle PUIS génère immédiatement N Session (§4.8).
            $payload['created_by'] = auth()->id();
            $payload['status'] = 'active';
            $this->template = SessionTemplate::create($payload);
            $this->template->categories()->sync($data['category_ids']);
            $this->template->defaultCoaches()->sync($data['coach_ids']);

            $created = $service->generate($this->template, auth()->user());
            session()->flash('status', $created->count().' séances générées · '.count($data['coach_ids']).' coachs.');
        }

        return $this->redirect(route('admin.templates'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.template-form', [
            'disciplines' => Discipline::whereNull('archived_at')->orderBy('sort_order')->get(),
            'quotaTags' => QuotaTag::whereNull('archived_at')->orderBy('label')->get(),
            'categories' => Category::whereNull('archived_at')->orderBy('sort_order')->get(),
            'locations' => Location::where('is_archived', false)->orderBy('name')->get(),
            'coaches' => User::whereJsonContains('roles', 'coach')->orderBy('last_name')->get(),
        ]);
    }
}
