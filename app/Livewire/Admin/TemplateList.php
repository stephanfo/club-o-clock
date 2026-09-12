<?php

namespace App\Livewire\Admin;

use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Services\TemplateGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    // ── Relance / prolongation (§4.8 Réutilisation), porté de RelanceModal ──

    public function openRelaunch(int $id): void
    {
        $tpl = SessionTemplate::findOrFail($id);
        $this->authorize('generate', $tpl);

        $this->relaunchId = $id;
        // Préremplit sur la saison suivante (preset « Nouvelle saison »).
        $this->relaunchStart = $tpl->generation_start_date->copy()->addYear()->toDateString();
        $this->relaunchEnd = $tpl->generation_end_date->copy()->addYear()->toDateString();
    }

    public function closeRelaunch(): void
    {
        $this->relaunchId = null;
    }

    public function relaunch(TemplateGenerationService $service): void
    {
        $tpl = SessionTemplate::findOrFail($this->relaunchId);
        $this->authorize('generate', $tpl);

        // Refus gardé CÔTÉ SERVEUR : le bouton grisé à 0 ne suffit pas, son état vient du client.
        // Un non-effet remonte en orange (flash('warn')), pas en vert.
        if ($this->missingOccurrences()->isEmpty()) {
            session()->flash('warn', 'Aucune séance à créer : la plage est déjà entièrement générée.');

            return;
        }

        $created = $service->relaunch(
            $tpl,
            auth()->user(),
            Carbon::parse($this->relaunchStart),
            Carbon::parse($this->relaunchEnd),
        );

        $this->relaunchId = null;
        session()->flash('status', $created->count().' nouvelles séances générées.');
    }

    /**
     * Aperçu live de la relance : occurrences de la plage saisie qui ne sont PAS déjà générées.
     * Compter les occurrences tout court mentait — le générateur est idempotent (une occurrence
     * déjà produite pour ce modèle n'est pas recréée), donc rejouer la plage courante annonçait
     * « N nouvelles séances » pour n'en créer aucune. Depuis le retrait de « Générer &
     * enregistrer », cette modale est le seul moyen de reboucher un trou : elle doit dire vrai.
     */
    public function getRelaunchCountProperty(): int
    {
        return $this->missingOccurrences()->count();
    }

    /**
     * Occurrences de [relaunchStart, relaunchEnd] sans séance déjà générée pour ce modèle.
     * La règle de comparaison est celle de l'idempotence du générateur : le JOUR local du club,
     * pas l'instant exact — une séance décalée par le bureau reste l'occurrence de son jour.
     *
     * @return Collection<int, Carbon>
     */
    private function missingOccurrences(): Collection
    {
        if ($this->relaunchId === null || ! $this->relaunchStart || ! $this->relaunchEnd) {
            return collect();
        }

        $tpl = SessionTemplate::find($this->relaunchId);
        if (! $tpl) {
            return collect();
        }

        $occurrences = app(TemplateGenerationService::class)
            ->occurrences($tpl, Carbon::parse($this->relaunchStart), Carbon::parse($this->relaunchEnd));

        if ($occurrences->isEmpty()) {
            return $occurrences;
        }

        $tz = ClubSettings::current()->timezone;
        $first = $occurrences->first();
        $last = $occurrences->last();
        $already = Session::where('source_template_id', $tpl->id)
            ->whereBetween('start_at', [
                Carbon::create($first->year, $first->month, $first->day, 0, 0, 0, $tz)->utc(),
                Carbon::create($last->year, $last->month, $last->day, 0, 0, 0, $tz)->endOfDay()->utc(),
            ])
            ->get(['start_at'])
            ->map(fn (Session $s) => $s->start_at->copy()->setTimezone($tz)->toDateString())
            ->all();

        return $occurrences->reject(fn (Carbon $d) => in_array($d->toDateString(), $already, true))->values();
    }

    public function render()
    {
        $templates = SessionTemplate::with(['discipline', 'quotaTag', 'defaultCoaches', 'categories', 'location'])
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
