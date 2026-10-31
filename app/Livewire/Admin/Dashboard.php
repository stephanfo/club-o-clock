<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\ClubSettings;
use App\Models\User;
use App\Services\StatsExportService;
use App\Services\StatsService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Dashboard statistiques bureau (PRD §4.16 — J6.6) — porté de screen-admin.jsx AdminDashboard.
// Indicateurs de pilotage (remplissage, top séances, waitlist, activité coachs…) + export XLSX.
// Trois filtres globaux pilotent toute la page : période, discipline, catégorie. Ouvert aux coachs
// en vue réduite (§4.16.3) : les blocs réservés ne sont même pas calculés pour eux.
#[Layout('layouts.app')]
#[Title('Statistiques')]
class Dashboard extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'view-pilotage';
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
        $this->authorize('view-dashboard');

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

        $coachActivity = $stats->coachActivity($f);
        $common = [
            'periodLabel' => $period['label'],
            'toStaff' => $stats->sessionsToStaff($f),
            'tz' => ClubSettings::current()->timezone,
            'weekly' => $stats->weeklyAttendance($f),
            'slots' => $stats->slots($f),
            'disciplines' => $options['disciplines'],
            'categories' => $options['categories'],
        ];

        if (! Gate::allows('view-dashboard')) {
            // Vue coach : sa seule ligne d'activité, sans la part encadrée par discipline.
            $mine = array_values(array_filter($coachActivity['rows'], fn ($r) => $r['id'] === auth()->id()));

            return view('livewire.admin.dashboard', $common + [
                'full' => false,
                'coachActivity' => ['disciplines' => $coachActivity['disciplines'], 'rows' => $mine, 'staffed' => []],
            ]);
        }

        return view('livewire.admin.dashboard', $common + [
            'full' => true,
            'dropouts' => $stats->dropouts($f),
            'headline' => $stats->headline($f),
            'unregistrations' => $stats->unregistrations($f),
            'members' => $stats->activeMembersByCategory($f),
            'competitions' => $stats->competitionsPerCourse($f),
            'usage' => $stats->appUsage(),
            'waitlist' => $stats->waitlist($f),
            'coachActivity' => $coachActivity,
            'eligibleCount' => User::deletionEligible()->count(),
        ]);
    }
}
