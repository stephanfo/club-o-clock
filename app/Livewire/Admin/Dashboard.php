<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\User;
use App\Services\StatsExportService;
use App\Services\StatsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Dashboard statistiques bureau (PRD §4.16 — J6.6) — porté de screen-admin.jsx AdminDashboard.
// Indicateurs de pilotage (remplissage, top séances, waitlist, activité coachs…) + export XLSX.
// Trois filtres globaux pilotent toute la page : période, discipline, catégorie. Admin uniquement.
#[Layout('layouts.app')]
#[Title('Statistiques')]
class Dashboard extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'view-dashboard';
    }

    /** Période : season | 30d | 90d | 12m. */
    #[Url]
    public string $period = 'season';

    /** Filtre discipline (id) ou null = toutes. */
    #[Url]
    public ?int $discipline = null;

    /** Filtre catégorie (id) ou null = toutes. */
    #[Url]
    public ?int $category = null;

    public function mount(): void
    {
        if (! in_array($this->period, StatsService::PERIODS, true)) {
            $this->period = 'season';
        }
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, StatsService::PERIODS, true)) {
            $this->period = $period;
        }
    }

    /** Filtre résolu (intervalle + axes) partagé par le rendu et l'export. */
    private function filter(StatsService $stats): array
    {
        $period = $stats->resolvePeriod($this->period);

        return [
            'from' => $period['from'],
            'to' => $period['to'],
            'discipline_id' => $this->discipline ?: null,
            'category_id' => $this->category ?: null,
        ];
    }

    /** Export XLSX (§4.16.2) reprenant les filtres en cours. Stream direct, sans fichier temporaire. */
    public function export(StatsService $stats, StatsExportService $export)
    {
        $period = $stats->resolvePeriod($this->period);
        $book = $export->build($this->filter($stats), $period);
        $filename = $export->filename();

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render()
    {
        $stats = app(StatsService::class);
        $f = $this->filter($stats);
        $period = $stats->resolvePeriod($this->period);
        $options = $stats->filterOptions();

        return view('livewire.admin.dashboard', [
            'periodLabel' => $period['label'],
            'headline' => $stats->headline($f),
            'monthly' => $stats->monthlyRegistrations($f),
            'topSessions' => $stats->topSessions($f),
            'waitlist' => $stats->waitlist($f),
            'coachActivity' => $stats->coachActivity($f),
            'eligibleCount' => User::deletionEligible()->count(),
            'disciplines' => $options['disciplines'],
            'categories' => $options['categories'],
        ]);
    }
}
