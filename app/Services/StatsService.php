<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Dashboard statistiques bureau (PRD §4.16.1 — J6.6). Calcule tous les indicateurs de pilotage à
// partir des données produites J2–J5 (inscriptions, quotas, encadrement). Trois filtres globaux —
// période, discipline, catégorie — appliqués partout où ils ont du sens. Service pur lecture : aucune
// mutation, aucun log. Les fenêtres « live » (séances futures sans coach) ignorent la période.
class StatsService
{
    public const PERIODS = ['season', '30d', '90d', '12m'];

    /**
     * Résout une clé de période en intervalle [from, to] + libellé. « season » = année sportive en
     * cours (début de saison → maintenant, mois d'ouverture réglable par le club §4.17), sinon
     * fenêtre glissante finissant maintenant.
     *
     * @return array{from:Carbon,to:Carbon,label:string}
     */
    public function resolvePeriod(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            '30d' => ['from' => $now->copy()->subDays(30), 'to' => $now, 'label' => '30 derniers jours'],
            '90d' => ['from' => $now->copy()->subDays(90), 'to' => $now, 'label' => '90 derniers jours'],
            '12m' => ['from' => $now->copy()->subMonthsNoOverflow(12), 'to' => $now, 'label' => '12 derniers mois'],
            default => [
                'from' => ClubSettings::current()->seasonStart($now)->startOfDay(),
                'to' => $now,
                'label' => 'Saison en cours',
            ],
        };
    }

    /**
     * @param  array{from:Carbon,to:Carbon,discipline_id?:?int,category_id?:?int}  $f
     */
    private function applySessionFilters(Builder $q, array $f): Builder
    {
        return $q
            ->when(! empty($f['discipline_id']), fn (Builder $q) => $q->where('discipline_id', $f['discipline_id']))
            ->when(! empty($f['category_id']), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey($f['category_id'])));
    }

    /** Séances training non annulées de la fenêtre, filtrées discipline/catégorie. */
    private function trainingSessions(array $f): Builder
    {
        return $this->applySessionFilters(
            Session::query()->where('kind', 'training')->whereNull('cancelled_at')
                ->whereBetween('start_at', [$f['from'], $f['to']]),
            $f
        );
    }

    // ── Bandeau KPI (§4.16.1) ──

    /**
     * 4 cartes d'en-tête : adhérents actifs, taux de remplissage, compétitions, overrides.
     *
     * @return array{active:int,new_since_season:int,fill_rate:?int,competitions:int,overrides:int}
     */
    public function headline(array $f): array
    {
        $seasonStart = $this->resolvePeriod('season')['from'];

        $active = $this->activeAthletesQuery($f);

        // Remplissage : sur les séances training à capacité bornée de la fenêtre.
        $sessions = $this->trainingSessions($f)->whereNotNull('capacity')->where('capacity', '>', 0)
            ->withCount(['registrations as participating_count' => fn (Builder $q) => $q->where('status', 'participating')])
            ->get(['id', 'capacity']);
        $capacity = $sessions->sum('capacity');
        $filled = $sessions->sum('participating_count');
        $fillRate = $capacity > 0 ? (int) round($filled / $capacity * 100) : null;

        // Filtre discipline volontairement absent : une compétition n'en porte jamais (§4.7), le
        // sélecteur global la ferait sinon toujours retomber à 0 dès qu'une discipline précise est
        // choisie. Seul le filtre catégorie — commun aux 3 kind — s'applique.
        $competitions = Session::query()->where('kind', 'competition')->whereNull('cancelled_at')
            ->whereBetween('start_at', [$f['from'], $f['to']])
            ->when(! empty($f['category_id']), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey($f['category_id'])))
            ->count();

        return [
            'active' => (clone $active)->count(),
            'new_since_season' => (clone $active)->where('created_at', '>=', $seasonStart)->count(),
            'fill_rate' => $fillRate,
            'competitions' => $competitions,
            'overrides' => $this->overridesQuery($f)->count(),
        ];
    }

    /** Athlètes actifs : rôle athlete, non anonymisé, actif, accès non suspendu, filtre catégorie. */
    private function activeAthletesQuery(array $f): Builder
    {
        return User::query()
            ->whereNull('anonymized_at')->where('is_active', true)->where('athlete_access_suspended', false)
            ->whereJsonContains('roles', 'athlete')
            ->when(! empty($f['category_id']), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey($f['category_id'])));
    }

    /**
     * Inscriptions forcées par coach (§4.16.1) de la fenêtre, filtre discipline via la séance liée.
     * Couvre les deux mécanismes : override de quota (B) et promotion d'un waitlisté au-delà du quota (C).
     */
    private function overridesQuery(array $f): Builder
    {
        return AuditLog::query()->whereIn('action', ['override_quota', 'promote_quota_exceeded'])
            ->whereBetween('created_at', [$f['from'], $f['to']])
            ->when(! empty($f['discipline_id']) || ! empty($f['category_id']), fn (Builder $q) => $q
                ->whereHas('session', fn (Builder $s) => $this->applySessionFilters($s, $f)));
    }

    // ── Évolution mensuelle des inscriptions (§4.16.1, PRD : « graphique d'inscriptions ») ──

    /** @return array<int,array{label:string,count:int}> barres par mois sur la fenêtre. */
    public function monthlyRegistrations(array $f): array
    {
        $rows = Registration::query()->where('status', 'participating')
            ->whereBetween('created_at', [$f['from'], $f['to']])
            ->whereHas('session', fn (Builder $s) => $this->applySessionFilters($s, $f))
            ->get(['created_at']);

        // Squelette de tous les mois de la fenêtre (zéros inclus), du plus ancien au plus récent.
        $buckets = [];
        $cursor = $f['from']->copy()->startOfMonth();
        $end = $f['to']->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $buckets[$cursor->format('Y-m')] = 0;
            $cursor->addMonthNoOverflow();
        }
        foreach ($rows as $r) {
            $key = $r->created_at->format('Y-m');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        $labels = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'];

        return collect($buckets)->map(fn ($count, $ym) => [
            'label' => $labels[(int) substr($ym, 5, 2) - 1],
            'count' => $count,
        ])->values()->all();
    }

    // ── Top séances par remplissage (§4.16.1) ──

    /** @return array<int,array{title:string,date:Carbon,fill:int}> */
    public function topSessions(array $f, int $limit = 4): array
    {
        return $this->trainingSessions($f)->whereNotNull('capacity')->where('capacity', '>', 0)
            ->withCount(['registrations as participating_count' => fn (Builder $q) => $q->where('status', 'participating')])
            ->get()
            ->map(fn (Session $s) => [
                'title' => $s->title,
                'date' => $s->start_at,
                'fill' => (int) round($s->participating_count / $s->capacity * 100),
            ])
            ->sortByDesc('fill')->take($limit)->values()->all();
    }

    // ── Liste d'attente (§4.16.1) ──

    /** @return array{total:int,capacity:int,quota:int,promotion_rate:?int} */
    public function waitlist(array $f): array
    {
        // File d'attente « vive » : seules les séances futures non annulées comptent (une waitlist sur
        // séance passée est périmée). Les promotions, elles, restent un fait historique sur la fenêtre.
        $waiting = Registration::query()->where('status', 'waitlist')
            ->whereHas('session', fn (Builder $s) => $this->applySessionFilters($s, $f)
                ->whereNull('cancelled_at')->where('start_at', '>', Carbon::now()));

        $total = (clone $waiting)->count();
        $byCapacity = (clone $waiting)->where('waitlist_reason', 'capacity')->count();
        $byQuota = (clone $waiting)->where('waitlist_reason', 'quota_exceeded')->count();

        // Taux de promotion : promus (promoted_at dans la fenêtre) / (promus + encore en attente).
        $promoted = Registration::query()->whereNotNull('promoted_at')
            ->whereBetween('promoted_at', [$f['from'], $f['to']])
            ->whereHas('session', fn (Builder $s) => $this->applySessionFilters($s, $f))
            ->count();
        $rate = ($promoted + $total) > 0 ? (int) round($promoted / ($promoted + $total) * 100) : null;

        return ['total' => $total, 'capacity' => $byCapacity, 'quota' => $byQuota, 'promotion_rate' => $rate];
    }

    // ── Activité coachs (§4.16.1, bloc dédié) ──

    /**
     * Séances training encadrées par coach × discipline sur la fenêtre + compteur live de séances
     * futures sans coach (hors période). Seuls les `training` comptent (pas competition/club_event).
     *
     * @return array{disciplines:Collection<int,Discipline>,rows:array<int,array{coach:string,by_discipline:array<int,int>,total:int}>,future_without_coach:int}
     */
    public function coachActivity(array $f): array
    {
        $disciplines = Discipline::query()->whereNull('archived_at')->orderBy('sort_order')->get();

        $sessions = $this->trainingSessions($f)->with(['coaches:id,first_name,last_name'])->get(['id', 'discipline_id']);

        // Agrégat coach → [discipline_id => count].
        $byCoach = [];
        foreach ($sessions as $s) {
            foreach ($s->coaches as $coach) {
                $byCoach[$coach->id] ??= ['coach' => $coach->fullName(), 'counts' => []];
                $byCoach[$coach->id]['counts'][$s->discipline_id] = ($byCoach[$coach->id]['counts'][$s->discipline_id] ?? 0) + 1;
            }
        }

        $rows = collect($byCoach)->map(function (array $c) use ($disciplines) {
            $byDiscipline = [];
            $total = 0;
            foreach ($disciplines as $d) {
                $n = $c['counts'][$d->id] ?? 0;
                $byDiscipline[$d->id] = $n;
                $total += $n;
            }

            return ['coach' => $c['coach'], 'by_discipline' => $byDiscipline, 'total' => $total];
        })->sortByDesc('total')->values()->all();

        $futureWithoutCoach = $this->applySessionFilters(
            Session::query()->where('kind', 'training')->whereNull('cancelled_at')
                ->where('start_at', '>', Carbon::now())->doesntHave('coaches'),
            $f
        )->count();

        return ['disciplines' => $disciplines, 'rows' => $rows, 'future_without_coach' => $futureWithoutCoach];
    }

    // ── Détails pour l'export XLSX (non affichés à l'écran, fidèle au design) ──

    /** @return array<int,array{label:string,count:int}> adhérents actifs par catégorie principale. */
    public function activeMembersByCategory(array $f): array
    {
        $members = $this->activeAthletesQuery($f)->with(['categories'])->get();

        $byCat = [];
        foreach ($members as $m) {
            $primary = $m->categories->firstWhere('pivot.is_primary', true);
            $label = $primary?->label ?? 'Sans catégorie';
            $byCat[$label] = ($byCat[$label] ?? 0) + 1;
        }
        arsort($byCat);

        return collect($byCat)->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all();
    }

    /** @return array<int,array{title:string,date:Carbon,participants:int}> participants déclarés par course. */
    public function competitionsPerCourse(array $f): array
    {
        return $this->applySessionFilters(
            Session::query()->where('kind', 'competition')->whereNull('cancelled_at')
                ->whereBetween('start_at', [$f['from'], $f['to']]),
            $f
        )
            ->withCount(['registrations as participants' => fn (Builder $q) => $q->where('status', 'participating')])
            ->orderBy('start_at')->get()
            ->map(fn (Session $s) => ['title' => $s->title, 'date' => $s->start_at, 'participants' => $s->participants])
            ->all();
    }

    /** @return array<int,array{motif:string,count:int}> overrides quota agrégés par motif. */
    public function overridesPerMotif(array $f): array
    {
        $byMotif = $this->overridesQuery($f)->get(['motif'])
            ->groupBy(fn (AuditLog $l) => $l->motif ?: '(sans motif)')
            ->map->count();

        return collect($byMotif)->map(fn ($count, $motif) => ['motif' => $motif, 'count' => $count])
            ->sortByDesc('count')->values()->all();
    }

    /** Catalogues pour les sélecteurs de filtres. @return array{disciplines:Collection,categories:Collection} */
    public function filterOptions(): array
    {
        return [
            'disciplines' => Discipline::query()->whereNull('archived_at')->orderBy('sort_order')->get(),
            'categories' => Category::query()->whereNull('archived_at')->orderBy('sort_order')->get(),
        ];
    }
}
