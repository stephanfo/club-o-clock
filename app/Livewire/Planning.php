<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithSubject;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Planning adhérent — 3 vues (semaine / jour / mois), mobile + desktop (PRD §4.7, ROADMAP_DEV J1).
// Fuseau club appliqué (invariant ROADMAP_DEV §30). Un parent garant peut consulter/inscrire un
// enfant via le sélecteur de sujet (§4.2, shell.jsx SubjectSwitcher) — jamais d'impersonation.
#[Layout('layouts.app')]
#[Title('Planning')]
class Planning extends Component
{
    use WithSubject;

    public const VIEWS = ['week', 'day', 'month'];

    #[Url]
    public string $view = 'week';

    /** Date d'ancrage ISO (le composant calcule la fenêtre autour). */
    #[Url]
    public string $anchor = '';

    // Filtres (PRD §4.7 : type, discipline, mes inscriptions, catégorie).
    #[Url]
    public string $kind = '';

    #[Url]
    public ?int $discipline = null;

    #[Url]
    public bool $mine = false;

    public function mount(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'week';
        }
        if ($this->anchor === '') {
            $this->anchor = $this->now()->toDateString();
        }
    }

    private function tz(): string
    {
        return ClubSettings::current()->timezone;
    }

    private function now(): Carbon
    {
        return Carbon::now($this->tz());
    }

    private function anchorDate(): Carbon
    {
        return Carbon::parse($this->anchor, $this->tz())->startOfDay();
    }

    public function setView(string $view): void
    {
        if (in_array($view, self::VIEWS, true)) {
            $this->view = $view;
        }
    }

    /** Filtre « Tout » : remet à zéro discipline ET kind (un seul wire:click possible par bouton). */
    public function filterAll(): void
    {
        $this->kind = '';
        $this->discipline = null;
    }

    /** Filtre discipline : exclusif avec le filtre « Compét. » (kind). */
    public function filterDiscipline(int $id): void
    {
        $this->discipline = $id;
        $this->kind = '';
    }

    /** Filtre « Compét. » : exclusif avec le filtre discipline. */
    public function filterCompetition(): void
    {
        $this->kind = 'competition';
        $this->discipline = null;
    }

    public function previous(): void
    {
        $this->anchor = $this->shift(-1)->toDateString();
    }

    public function next(): void
    {
        $this->anchor = $this->shift(1)->toDateString();
    }

    public function today(): void
    {
        $this->anchor = $this->now()->toDateString();
    }

    public function goToWeek(string $date): void
    {
        $this->view = 'week';
        $this->anchor = Carbon::parse($date, $this->tz())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function goToDay(string $date): void
    {
        $this->view = 'day';
        $this->anchor = Carbon::parse($date, $this->tz())->toDateString();
    }

    private function shift(int $direction): Carbon
    {
        return match ($this->view) {
            'month' => $this->anchorDate()->addMonths($direction),
            'day' => $this->anchorDate()->addDays($direction),
            default => $this->anchorDate()->addWeeks($direction), // week + list
        };
    }

    /** Fenêtre [début, fin] selon la vue, dans le fuseau club. */
    private function window(): array
    {
        $a = $this->anchorDate();

        return match ($this->view) {
            'month' => [$a->copy()->startOfMonth(), $a->copy()->endOfMonth()],
            'day' => [$a->copy()->startOfDay(), $a->copy()->endOfDay()],
            default => [$a->copy()->startOfWeek(Carbon::MONDAY), $a->copy()->endOfWeek(Carbon::SUNDAY)],
        };
    }

    /**
     * Fenêtre effectivement interrogée. En vue Mois, la grille affiche des semaines complètes :
     * les jours débordants (fin du mois précédent / début du suivant) doivent porter leurs séances,
     * sinon ils paraissent vides à tort. Ailleurs, la fenêtre de requête = la fenêtre affichée.
     */
    private function queryWindow(): array
    {
        [$from, $to] = $this->window();

        if ($this->view === 'month') {
            return [$from->copy()->startOfWeek(Carbon::MONDAY), $to->copy()->endOfWeek(Carbon::SUNDAY)];
        }

        return [$from, $to];
    }

    /** @return Collection<int, Session> */
    private function sessions(): Collection
    {
        [$from, $to] = $this->queryWindow();

        $query = Session::query()
            ->with(['discipline', 'location', 'coaches', 'registrations', 'activeAperoFlags', 'quotaTag', 'categories'])
            // Bornes converties en UTC (start_at stocké en UTC ; Laravel sérialise les Carbon dans
            // LEUR fuseau, sans conversion — la fenêtre en fuseau club serait décalée de 1-2 h).
            ->whereBetween('start_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('start_at');

        if ($this->kind !== '') {
            $query->where('kind', $this->kind);
        }
        if ($this->discipline) {
            $query->where('discipline_id', $this->discipline);
        }
        if ($this->mine && auth()->check()) {
            // « Mes inscriptions » suit le sujet consulté (parent → enfant, §4.2).
            $userId = $this->subject()->id;
            $query->whereHas('registrations', function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereIn('status', ['participating', 'waitlist']);
            });
        }

        // Filtrage par catégorie (§4.5) : suit le sujet consulté (parent → enfant, §4.2).
        $query->visibleToCategories($this->subject());

        return $query->get();
    }

    public function render()
    {
        [$from, $to] = $this->window();
        $sessions = $this->sessions();
        $tz = $this->tz();
        $grouped = $sessions->groupBy(fn (Session $s) => $s->start_at->copy()->setTimezone($tz)->toDateString());

        // Jours de la semaine (lun→dim) pour la grille hebdo, même vides.
        $weekDays = [];
        if ($this->view === 'week') {
            $cursor = $from->copy();
            while ($cursor <= $to) {
                $weekDays[] = $cursor->copy();
                $cursor->addDay();
            }
        }

        // Grille calendaire du mois (lun→dim, semaines complètes débordantes) pour la vue Mois.
        // Mêmes bornes que queryWindow() : les jours débordants portent donc leurs séances.
        $monthGrid = [];
        if ($this->view === 'month') {
            [$gridStart, $gridEnd] = $this->queryWindow();
            $cursor = $gridStart->copy();
            while ($cursor <= $gridEnd) {
                $monthGrid[] = $cursor->copy();
                $cursor->addDay();
            }
        }

        return view('livewire.planning', [
            'sessions' => $sessions,
            'grouped' => $grouped,
            'weekDays' => $weekDays,
            'monthGrid' => $monthGrid,
            'from' => $from,
            'to' => $to,
            'disciplines' => Discipline::whereNull('archived_at')->orderBy('sort_order')->get(),
            'tz' => $tz,
            'todayStr' => $this->now()->toDateString(),
            ...$this->subjectViewData(),
        ]);
    }
}
