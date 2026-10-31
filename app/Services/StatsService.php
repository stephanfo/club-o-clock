<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\SessionTemplate;
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @param  array{from:Carbon,to:Carbon,discipline_id?:?int,category_id?:?int}  $f
     * @return Builder<TModel>
     */
    private function applySessionFilters(Builder $q, array $f): Builder
    {
        return $q
            ->when(! empty($f['discipline_id']), fn (Builder $q) => $q->where('discipline_id', $f['discipline_id']))
            ->when(! empty($f['category_id']), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey($f['category_id'])));
    }

    /**
     * Séances training non annulées de la fenêtre, filtrées discipline/catégorie.
     *
     * @return Builder<Session>
     */
    private function trainingSessions(array $f): Builder
    {
        return $this->applySessionFilters(
            Session::query()->where('kind', 'training')->whereNull('cancelled_at')
                ->whereBetween('start_at', [$f['from'], $f['to']]),
            $f
        );
    }

    // ── Chiffres clés (§4.16.2, bloc 3) ──

    /**
     * Cartes d'en-tête : pratiquants / comptes, participations par semaine, remplissage, compétiteurs.
     * `new_since_season` et `overrides` ne sont plus affichés (§4.16.2) mais restent dans l'export.
     *
     * @return array{active:int,practitioners:int,weekly_avg:?int,fill_rate:?int,fill_sessions:int,competitors:int,competitions:int,new_since_season:int,overrides:int}
     */
    public function headline(array $f): array
    {
        $seasonStart = $this->resolvePeriod('season')['from'];

        $active = $this->activeAthletesQuery($f);

        // Remplissage : sur les séances training à capacité bornée de la fenêtre. Leur nombre est rendu
        // avec le taux : une discipline sans capacité (vélo) n'y entre pas, et l'écran le dit.
        $sessions = $this->trainingSessions($f)->whereNotNull('capacity')->where('capacity', '>', 0)
            ->withCount(['registrations as participating_count' => fn (Builder $q) => $q->where('status', 'participating')])
            ->get(['id', 'capacity']);
        $capacity = $sessions->sum('capacity');
        $filled = $sessions->sum('participating_count');
        $fillRate = $capacity > 0 ? (int) round($filled / $capacity * 100) : null;

        // Pratiquants : parmi les comptes athlètes actifs, ceux qui ont au moins une participation à
        // une séance tenue. Le dénominateur est le même ensemble, donc le rapport ne dépasse pas 100 %.
        $practitioners = (clone $active)->whereHas('registrations', fn (Builder $r) => $r
            ->where('status', 'participating')
            ->whereIn('session_id', $this->heldTrainingSessions($f)->select('sessions.id')))
            ->count();

        // Participations par semaine : moyenne sur les seules semaines où le club a proposé une séance —
        // deux semaines de vacances ne doivent pas faire croire à une baisse de fréquentation.
        $weeks = collect($this->weeklyAttendance($f))->where('sessions', '>', 0);
        $weeklyAvg = $weeks->isNotEmpty() ? (int) round($weeks->sum('participations') / $weeks->count()) : null;

        // Filtre discipline volontairement absent : une compétition n'en porte jamais (§4.7), le
        // sélecteur global la ferait sinon toujours retomber à 0 dès qu'une discipline précise est
        // choisie. Seul le filtre catégorie — commun aux 3 kind — s'applique.
        $competitions = Session::query()->where('kind', 'competition')->whereNull('cancelled_at')
            ->whereBetween('start_at', [$f['from'], $f['to']])
            ->when(! empty($f['category_id']), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey($f['category_id'])));

        $competitors = Registration::query()->where('status', 'participating')
            ->whereIn('session_id', (clone $competitions)->select('sessions.id'))
            ->distinct()->count('user_id');

        return [
            'active' => (clone $active)->count(),
            'practitioners' => $practitioners,
            'weekly_avg' => $weeklyAvg,
            'fill_rate' => $fillRate,
            'fill_sessions' => $sessions->count(),
            'competitors' => $competitors,
            'competitions' => $competitions->count(),
            'new_since_season' => (clone $active)->where('created_at', '>=', $seasonStart)->count(),
            'overrides' => $this->overridesQuery($f)->count(),
        ];
    }

    /**
     * Séances training de la fenêtre déjà commencées : une séance future n'a pas encore « eu lieu ».
     *
     * @return Builder<Session>
     */
    private function heldTrainingSessions(array $f): Builder
    {
        return $this->trainingSessions($f)->where('start_at', '<=', Carbon::now());
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

    // ── Fréquentation hebdomadaire (§4.16.2, bloc 4) ──

    /**
     * Par semaine (lundi → dimanche, heure du club) : séances tenues, participations et athlètes
     * distincts, à la DATE DE LA SÉANCE — une inscription prise en septembre pour octobre compte en
     * octobre. Semaines sans séance présentes à zéro (vacances visibles) ; la semaine en cours est
     * marquée partielle ; rien au-delà de maintenant.
     *
     * Le regroupement se fait en base : un CASE sur les bornes UTC de chaque semaine locale, ce qui
     * reste exact aux changements d'heure sans dépendre des tables de fuseaux du SGBD.
     *
     * @return array<int,array{start:Carbon,sessions:int,participations:int,athletes:int,partial:bool}>
     */
    public function weeklyAttendance(array $f): array
    {
        $tz = ClubSettings::current()->timezone;
        $now = Carbon::now();
        $end = $f['to']->lt($now) ? $f['to'] : $now;

        $starts = [];
        $cursor = $f['from']->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY);
        $last = $end->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY);
        while ($cursor->lte($last)) {
            $starts[] = $cursor->copy();
            $cursor->addWeek();
        }

        if ($starts === []) {
            return [];
        }

        // Bornes hautes UTC de chaque semaine : WHEN start_at < borne(i) THEN i.
        $bounds = array_map(fn (Carbon $s) => $s->copy()->addWeek()->utc()->format('Y-m-d H:i:s'), $starts);
        $case = 'CASE '.implode(' ', array_map(fn ($i) => "WHEN sessions.start_at < ? THEN $i", array_keys($bounds))).' END';

        $held = $this->heldTrainingSessions($f);

        $sessionsPerWeek = (clone $held)->toBase()
            ->selectRaw("$case as w, COUNT(*) as n", $bounds)
            ->groupBy('w')->pluck('n', 'w');

        $attendance = Registration::query()->toBase()
            ->join('sessions', 'sessions.id', '=', 'registrations.session_id')
            ->where('registrations.status', 'participating')
            ->whereIn('registrations.session_id', (clone $held)->select('sessions.id'))
            ->selectRaw("$case as w, COUNT(*) as n, COUNT(DISTINCT registrations.user_id) as a", $bounds)
            ->groupBy('w')->get()->keyBy('w');

        $currentWeek = $now->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY);

        return array_map(fn (int $i) => [
            'start' => $starts[$i],
            'sessions' => (int) ($sessionsPerWeek[$i] ?? 0),
            'participations' => (int) ($attendance[$i]->n ?? 0),
            'athletes' => (int) ($attendance[$i]->a ?? 0),
            'partial' => $starts[$i]->equalTo($currentWeek),
        ], array_keys($starts));
    }

    // ── Créneaux (§4.16.2, bloc 6) ──

    /**
     * Une ligne par modèle de séance (§4.8) ayant produit des séances tenues sur la période, archivés
     * compris : un créneau supprimé en cours de saison a quand même eu lieu. Les séances hors modèle
     * sont regroupées par discipline en « ponctuelles ». Triées par moyenne d'inscrits décroissante,
     * pour que les créneaux qui ne prennent pas tombent en bas.
     *
     * @return array<int,array{label:string,day:?string,time:?string,discipline:?Discipline,sessions:int,avg:float,min:int,max:int,fill:?int,unstaffed:int}>
     */
    public function slots(array $f): array
    {
        $sessions = $this->heldTrainingSessions($f)
            ->with('discipline')
            ->withCount([
                'registrations as participating_count' => fn (Builder $q) => $q->where('status', 'participating'),
                'coaches',
            ])
            ->get(['id', 'source_template_id', 'discipline_id', 'capacity', 'external_staff_label']);

        $templates = SessionTemplate::query()
            ->whereIn('id', $sessions->pluck('source_template_id')->filter()->unique())
            ->get(['id', 'label', 'day_of_week', 'start_time_of_day'])->keyBy('id');

        $days = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

        return $sessions
            // Un id de modèle disparu (modèle supprimé, FK mise à null ou orpheline) retombe en ponctuelle.
            ->groupBy(fn (Session $s) => $templates->has($s->source_template_id)
                ? 'm'.$s->source_template_id
                : 'p'.$s->discipline_id)
            ->map(function (Collection $group) use ($templates, $days) {
                $first = $group->first();
                $template = $templates->get($first->source_template_id);
                $counts = $group->pluck('participating_count');

                // Remplissage : sur les seules séances à capacité du créneau — null si aucune, pour
                // afficher « — » et non un 0 % qui ferait croire à un créneau vide.
                $bounded = $group->filter(fn (Session $s) => $s->capacity > 0);
                $capacity = $bounded->sum('capacity');

                // Sans encadrement : ni coach inscrit ni intervenant extérieur (#38).
                $unstaffed = $group->filter(fn (Session $s) => $s->coaches_count === 0 && blank($s->external_staff_label))->count();

                return [
                    'label' => $template->label ?? 'Séances ponctuelles',
                    'day' => $template ? $days[$template->day_of_week] ?? null : null,
                    'time' => $template ? substr((string) $template->start_time_of_day, 0, 5) : null,
                    'discipline' => $first->discipline,
                    'sessions' => $group->count(),
                    'avg' => round($counts->avg(), 1),
                    'min' => (int) $counts->min(),
                    'max' => (int) $counts->max(),
                    'fill' => $capacity > 0 ? (int) round($bounded->sum('participating_count') / $capacity * 100) : null,
                    'unstaffed' => (int) round($unstaffed / $group->count() * 100),
                ];
            })
            ->sortByDesc('avg')->values()->all();
    }

    // ── Désinscriptions (§4.16.2, bloc 7) ──

    /** Une désinscription est « tardive » quand elle intervient moins de tant d'heures avant le début. */
    public const LATE_UNREGISTRATION_HOURS = 24;

    /**
     * Par discipline : désinscriptions sur les séances training non annulées de la période, et celles
     * faites trop tard pour que la place soit reprise. L'horodatage vient du journal d'activité
     * (§4.18) : `registrations.updated_at` bouge à chaque changement de la ligne, une désinscription
     * suivie d'une réinscription l'effacerait. Une séance annulée sort du calcul : ses inscrits n'ont
     * pas choisi de se retirer.
     *
     * @return array{rows:array<int,array{discipline:?Discipline,total:int,late:int,late_pct:int}>,total:int,late:int,late_pct:?int}
     */
    public function unregistrations(array $f): array
    {
        $logs = ActivityLog::query()
            ->join('sessions', 'sessions.id', '=', 'activity_logs.session_id')
            ->whereIn('activity_logs.action', ['desinscription', 'desinscription_for_other'])
            ->whereIn('activity_logs.session_id', $this->trainingSessions($f)->select('sessions.id'))
            ->get(['activity_logs.created_at', 'sessions.start_at', 'sessions.discipline_id']);

        $late = fn ($l) => Carbon::parse($l->created_at)->greaterThan(Carbon::parse($l->start_at)->subHours(self::LATE_UNREGISTRATION_HOURS));
        $disciplines = Discipline::query()->whereIn('id', $logs->pluck('discipline_id')->filter()->unique())->get()->keyBy('id');

        $rows = $logs->groupBy('discipline_id')->map(function (Collection $group, $id) use ($late, $disciplines) {
            $n = $group->filter($late)->count();

            return [
                'discipline' => $disciplines->get($id),
                'total' => $group->count(),
                'late' => $n,
                'late_pct' => (int) round($n / $group->count() * 100),
            ];
        })->sortByDesc('total')->values()->all();

        $total = $logs->count();
        $lateTotal = $logs->filter($late)->count();

        return [
            'rows' => $rows,
            'total' => $total,
            'late' => $lateTotal,
            'late_pct' => $total > 0 ? (int) round($lateTotal / $total * 100) : null,
        ];
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
            // Un intervenant extérieur signalé (#38) vaut encadrement : la piscine du dimanche
            // tenue par un surveillant prestataire n'est pas une séance « sans coach » à traiter.
            Session::query()->where('kind', 'training')->whereNull('cancelled_at')
                ->where('start_at', '>', Carbon::now())->doesntHave('coaches')
                ->whereNull('external_staff_label'),
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
            $label = $primary->label ?? 'Sans catégorie';
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
            ->map(fn (Session $s) => ['title' => $s->title, 'date' => $s->start_at, 'participants' => (int) $s->getAttribute('participants')])
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
