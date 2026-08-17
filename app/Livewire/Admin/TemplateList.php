<?php

namespace App\Livewire\Admin;

use App\Models\SessionTemplate;
use App\Services\TemplateGenerationService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Écran admin « Modèles de génération » (PRD §4.8), porté de screen-admin.jsx AdminModeles.
// Master/détail : liste actifs + archivés à gauche, panneau du modèle sélectionné à droite
// (relance / archive / regénération). Admin uniquement (SessionTemplatePolicy).
#[Layout('layouts.app')]
#[Title('Modèles de séances')]
class TemplateList extends Component
{
    /** Modèle sélectionné dans le panneau de détail. */
    public ?int $selectedId = null;

    /** Modale de relance ouverte sur ce modèle ? + plage saisie. */
    public ?int $relaunchId = null;

    /** Jour ISO du modèle relancé (mémorisé à l'ouverture → aperçu sans re-query). */
    public ?int $relaunchDay = null;

    public string $relaunchStart = '';

    public string $relaunchEnd = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SessionTemplate::class);
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    /** Archivage = soft (status archived) : arrête de générer, n'efface pas les séances (§4.8). */
    public function archive(int $id): void
    {
        $tpl = SessionTemplate::findOrFail($id);
        $this->authorize('archive', $tpl);

        $tpl->update(['status' => 'archived']);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
        session()->flash('status', 'Modèle archivé · ne génère plus de séances.');
    }

    public function reactivate(int $id): void
    {
        $tpl = SessionTemplate::findOrFail($id);
        $this->authorize('update', $tpl);

        $tpl->update(['status' => 'active']);
        $this->selectedId = $id;
        session()->flash('status', 'Modèle réactivé.');
    }

    /** (Re)génère la plage stockée du modèle — bouton « Générer & enregistrer » (§4.8). */
    public function generate(int $id, TemplateGenerationService $service): void
    {
        $tpl = SessionTemplate::findOrFail($id);
        $this->authorize('generate', $tpl);

        $created = $service->generate($tpl, auth()->user());
        session()->flash('status', $created->count().' séances générées.');
    }

    // ── Relance / prolongation (§4.8 Réutilisation), porté de RelanceModal ──

    public function openRelaunch(int $id): void
    {
        $tpl = SessionTemplate::findOrFail($id);
        $this->authorize('generate', $tpl);

        $this->relaunchId = $id;
        $this->relaunchDay = $tpl->day_of_week;
        // Préremplit sur la saison suivante (preset « Nouvelle saison »).
        $this->relaunchStart = $tpl->generation_start_date->copy()->addYear()->toDateString();
        $this->relaunchEnd = $tpl->generation_end_date->copy()->addYear()->toDateString();
    }

    public function closeRelaunch(): void
    {
        $this->relaunchId = null;
        $this->relaunchDay = null;
    }

    public function relaunch(TemplateGenerationService $service): void
    {
        $tpl = SessionTemplate::findOrFail($this->relaunchId);
        $this->authorize('generate', $tpl);

        $created = $service->relaunch(
            $tpl,
            auth()->user(),
            Carbon::parse($this->relaunchStart),
            Carbon::parse($this->relaunchEnd),
        );

        $this->relaunchId = null;
        session()->flash('status', $created->count().' nouvelles séances générées.');
    }

    /** Aperçu live du nombre d'occurrences de la plage de relance saisie (sans re-query : day mémorisé). */
    public function getRelaunchCountProperty(): int
    {
        if ($this->relaunchDay === null || ! $this->relaunchStart || ! $this->relaunchEnd) {
            return 0;
        }

        $probe = new SessionTemplate(['day_of_week' => $this->relaunchDay]);

        return app(TemplateGenerationService::class)
            ->occurrences($probe, Carbon::parse($this->relaunchStart), Carbon::parse($this->relaunchEnd))
            ->count();
    }

    public function render()
    {
        $templates = SessionTemplate::with(['discipline', 'quotaTag', 'defaultCoaches'])
            ->withCount('sessions')
            ->orderBy('label')
            ->get();

        $active = $templates->where('status', 'active')->values();
        $archived = $templates->where('status', 'archived')->values();

        $selected = $this->selectedId
            ? $active->firstWhere('id', $this->selectedId)
            : $active->first();

        return view('livewire.admin.template-list', [
            'active' => $active,
            'archived' => $archived,
            'selected' => $selected,
            'relaunchTpl' => $this->relaunchId ? $templates->firstWhere('id', $this->relaunchId) : null,
        ]);
    }
}
