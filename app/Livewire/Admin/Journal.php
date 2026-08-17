<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use App\Services\JournalExportService;
use App\Services\JournalService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Page « Journaux » (PRD §4.18.5 — J6.7) — porté de screen-admin.jsx AdminJournaux.
// Consultation filtrée des journaux Audit/Activity (séparés ou fusionnés), chronologie DESC
// « charger plus », autocomplete acteur/séance, multi-select action, drawer détail + export XLSX.
// Admin uniquement (Gate view-journal). Lecture seule : aucune écriture.
#[Layout('layouts.app')]
#[Title('Journaux')]
class Journal extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'view-journal';
    }

    /** Source : all | audit | activity. */
    #[Url]
    public string $source = 'all';

    /** Période : 30d | 90d | season | all (défaut 30 j, §4.18.5). */
    #[Url]
    public string $period = '30d';

    /** Acteur sélectionné (autocomplete) — id exact + libellé affiché. */
    #[Url]
    public ?int $actorId = null;

    public string $actorLabel = '';

    /** Saisie courante de l'autocomplete acteur (non persistée). */
    public string $actorQuery = '';

    /** Séance sélectionnée (autocomplete). */
    #[Url]
    public ?int $sessionId = null;

    public string $sessionLabel = '';

    public string $sessionQuery = '';

    /** Actions cochées (multi-select). @var array<int,string> */
    #[Url]
    public array $actions = [];

    /** Type de cible (AuditLog uniquement). */
    #[Url]
    public ?string $targetType = null;

    /** Fenêtre « charger plus ». */
    public int $perPage = 25;

    /** Drawer détail : ligne décorée ouverte, ou null. @var array<string,mixed>|null */
    public ?array $detail = null;

    public function mount(): void
    {
        if (! in_array($this->source, JournalService::SOURCES, true)) {
            $this->source = 'all';
        }
        if (! in_array($this->period, JournalService::PERIODS, true)) {
            $this->period = '30d';
        }
    }

    public function setSource(string $source): void
    {
        if (in_array($source, JournalService::SOURCES, true)) {
            $this->source = $source;
            $this->resetPage();
        }
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, JournalService::PERIODS, true)) {
            $this->period = $period;
            $this->resetPage();
        }
    }

    /** Bascule une action dans le multi-select. */
    public function toggleAction(string $action): void
    {
        if (($i = array_search($action, $this->actions, true)) !== false) {
            unset($this->actions[$i]);
            $this->actions = array_values($this->actions);
        } else {
            $this->actions[] = $action;
        }
        $this->resetPage();
    }

    /** Reçoit le nom court (pas le FQCN, qui casserait wire:click avec ses backslashes) et le résout. */
    public function setTargetType(?string $short, JournalService $journal): void
    {
        $this->targetType = $short ? (array_search($short, $journal->targetTypes(), true) ?: null) : null;
        $this->resetPage();
    }

    public function selectActor(int $id): void
    {
        // Label résolu serveur (jamais passé dans wire:click → robuste aux apostrophes des noms).
        if ($u = User::find($id)) {
            $this->actorId = $id;
            $this->actorLabel = trim($u->first_name.' '.$u->last_name);
        }
        $this->actorQuery = '';
        $this->resetPage();
    }

    public function clearActor(): void
    {
        $this->reset('actorId', 'actorLabel', 'actorQuery');
        $this->resetPage();
    }

    public function selectSession(int $id): void
    {
        if ($s = Session::find($id)) {
            $this->sessionId = $id;
            $this->sessionLabel = $s->title.' · '.$s->start_at->format('d/m/Y');
        }
        $this->sessionQuery = '';
        $this->resetPage();
    }

    public function clearSession(): void
    {
        $this->reset('sessionId', 'sessionLabel', 'sessionQuery');
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('source', 'period', 'actorId', 'actorLabel', 'actorQuery',
            'sessionId', 'sessionLabel', 'sessionQuery', 'actions', 'targetType');
        $this->source = 'all';
        $this->period = '30d';
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 25;
    }

    private function resetPage(): void
    {
        $this->perPage = 25;
    }

    public function showDetail(string $source, int $id, JournalService $journal): void
    {
        // Defense-in-depth : c'est l'action qui renvoie de la donnée sensible (motif, cible).
        $this->detail = $journal->find($source, $id);
    }

    public function closeDetail(): void
    {
        $this->detail = null;
    }

    /** Filtres résolus (intervalle + axes) partagés par le rendu et l'export. */
    private function filters(JournalService $journal): array
    {
        $period = $journal->resolvePeriod($this->period);

        return [
            'source' => $this->source,
            'actor_id' => $this->actorId,
            'actions' => $this->actions,
            'target_type' => $this->source === 'activity' ? null : $this->targetType,
            'session_id' => $this->sessionId,
            'from' => $period['from'],
            'to' => $period['to'],
        ];
    }

    /** Export XLSX (§4.18.5) reprenant les filtres en cours. Stream direct, sans fichier temporaire. */
    public function export(JournalService $journal, JournalExportService $export)
    {
        $book = $export->build($this->filters($journal));
        $filename = $export->filename();

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render(JournalService $journal)
    {
        $page = $journal->page($this->filters($journal), $this->perPage);
        $period = $journal->resolvePeriod($this->period);

        return view('livewire.admin.journal', [
            'tz' => ClubSettings::current()->timezone,
            'rows' => $page['rows'],
            'total' => $page['total'],
            'periodLabel' => $period['label'],
            'actorSuggestions' => $this->actorId ? [] : $journal->actorSuggestions($this->actorQuery),
            'sessionSuggestions' => $this->sessionId ? [] : $journal->sessionSuggestions($this->sessionQuery),
            'actionOptions' => $journal->availableActions(),
            'targetTypeOptions' => $journal->targetTypes(),
        ]);
    }
}
