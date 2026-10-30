<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithSubject;
use App\Models\ClubSettings;
use App\Models\InformationPage;
use App\Models\Session;
use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Services\QuotaService;
use App\Support\Trombi\ConsentType;
use App\Support\Trombi\TrombiCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Accueil (PRD §2) — porté de screen-home.jsx. Greeting + prochaine séance + à venir cette semaine.
// Le sélecteur de sujet parent (§4.2) pilote les indicateurs perso (participe / quotas).
#[Layout('layouts.app')]
#[Title('Accueil')]
class Home extends Component
{
    use WithSubject;

    private function tz(): string
    {
        return ClubSettings::current()->timezone;
    }

    /** Fenêtre du bloc « Derniers débriefs » : au-delà, le débrief a déjà été lu (retour terrain). */
    public const DEBRIEFS_RECENTS_JOURS = 15;

    /** @return Collection<int, Session> */
    private function recentDebriefs(Carbon $depuisUtc)
    {
        $recent = fn ($q) => $q->active()->where('created_at', '>=', $depuisUtc);

        return Session::query()
            ->whereHas('debriefs', $recent)
            ->with(['debriefs' => fn ($q) => $recent($q)->with('author:id,first_name')->latest()])
            ->get()
            ->sortByDesc(fn (Session $s) => $s->debriefs->max('created_at'))
            ->take(5)
            ->values();
    }

    /**
     * « Anniversaires de la semaine » (§4.23.6) : jour et prénom, sous accord, du lundi au dimanche
     * de la semaine en cours — à cheval sur deux années la dernière semaine de décembre. Un
     * 29 février se fête le 28 les années non bissextiles.
     *
     * @return list<array{id:int, first_name:string, date:Carbon, photo:?string}>
     */
    private function weekBirthdays(Carbon $now, User $viewer, TrombiPolicy $policy): array
    {
        $days = [];
        $monday = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $days[$day->format('m-d')] = $day;
            if ($day->format('m-d') === '02-28' && ! $day->isLeapYear()) {
                $days['02-29'] = $day;
            }
        }

        return User::query()->auTrombinoscope()->whereNotNull('dob')
            ->whereHas('unrevokedConsents', fn ($q) => $q->where('type', ConsentType::Birthday->value))
            ->with('unrevokedConsents')
            ->get()
            ->filter(fn (User $u) => $policy->birthday($u) && isset($days[$u->dob->format('m-d')]))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'date' => $days[$u->dob->format('m-d')],
                'photo' => TrombiCard::photoUrl($viewer, $u, 192, $policy),
            ])
            ->sortBy([fn ($a, $b) => $a['date'] <=> $b['date'], fn ($a, $b) => strcmp($a['first_name'], $b['first_name'])])
            ->values()
            ->all();
    }

    public function render(TrombiPolicy $policy)
    {
        $tz = $this->tz();
        $now = Carbon::now($tz);
        // Bornes SQL en UTC : start_at est stocké en UTC et Laravel sérialise les Carbon dans
        // LEUR fuseau, sans conversion — une borne en fuseau club serait décalée de 1-2 h.
        $nowUtc = $now->copy()->utc();
        $subject = auth()->check() ? $this->subject() : null;

        // Séances à venir, non annulées, horizon proche.
        // Filtrées par catégorie du sujet (§4.5) : le héros ne montre que des séances qui le concernent.
        $upcoming = Session::query()
            ->with(['discipline', 'location', 'registrations', 'activeAperoFlags', 'quotaTag', 'coaches'])
            ->whereNull('cancelled_at')
            ->where('start_at', '>=', $nowUtc)
            ->visibleToCategories($subject)
            ->orderBy('start_at')
            ->limit(6)
            ->get();

        $next = $upcoming->first();
        // Indicateurs perso calculés pour le SUJET consulté (soi, ou enfant garanti — §4.2).
        $uid = $subject?->id;

        // « Mes prochaines séances » : celles où le SUJET est inscrit, place ferme (participating)
        // OU liste d'attente (waitlist). La carte de séance affiche le chip de statut distinct.
        // Le héros ($next) reste la prochaine séance du club ; cette liste est personnelle.
        // S'y ajoutent les séances que le sujet ENCADRE (session_coach, sans Registration) : la carte
        // les marque « Tu encadres » (retour terrain — elles manquaient à l'accueil d'un coach).
        $myUpcoming = $uid
            ? Session::query()
                ->with(['discipline', 'location', 'registrations', 'activeAperoFlags', 'quotaTag', 'coaches'])
                ->whereNull('cancelled_at')
                ->where('start_at', '>=', $nowUtc)
                ->where(fn ($q) => $q
                    ->whereHas('registrations', fn ($r) => $r->where('user_id', $uid)->whereIn('status', ['participating', 'waitlist']))
                    ->orWhereHas('coaches', fn ($c) => $c->whereKey($uid)))
                ->orderBy('start_at')
                ->limit(6)
                ->get()
            : collect();
        // Le héros affiche déjà $next (prochaine séance du club) : on l'écarte de la liste perso pour éviter le doublon.
        $myUpcoming = $myUpcoming->reject(fn ($s) => $next && $s->id === $next->id)->values();
        $nextIsCoach = auth()->id() && $next && $subject?->id === auth()->id() && $next->coaches->contains('id', auth()->id());
        $nextIsParticipant = $uid && $next && $next->registrations->where('user_id', $uid)->where('status', 'participating')->isNotEmpty();

        // Trombinoscope (§4.23.6) : lien depuis l'accueil mobile, anniversaires de la semaine.
        $viewer = auth()->user();
        $canTrombi = $viewer !== null && $policy->consult($viewer);

        return view('livewire.home', [
            'canTrombi' => $canTrombi,
            'weekBirthdays' => $canTrombi ? $this->weekBirthdays($now, $viewer, $policy) : [],
            'tz' => $tz,
            'now' => $now,
            'next' => $next,
            'nextIsCoach' => $nextIsCoach,
            'nextIsParticipant' => $nextIsParticipant,
            'myUpcoming' => $myUpcoming,
            // « Apéro à venir » (§4.14.5 affordance 3) : séances futures avec au moins un flag actif.
            'aperoUpcoming' => Session::query()
                ->with(['discipline', 'location'])
                ->whereNull('cancelled_at')
                ->where('start_at', '>=', $nowUtc)
                ->whereHas('activeAperoFlags')
                ->orderBy('start_at')
                ->limit(5)
                ->get(),
            // « Derniers débriefs » (§4.12.5, #104) : compétitions débriefées depuis moins de 15 jours.
            // Lu par tout le club, sans filtre de catégorie (cohérent avec l'annonce #111).
            'recentDebriefs' => $this->recentDebriefs($nowUtc->copy()->subDays(self::DEBRIEFS_RECENTS_JOURS)),
            'weekCount' => Session::whereNull('cancelled_at')
                ->whereBetween('start_at', [$now->copy()->startOfWeek(Carbon::MONDAY)->utc(), $now->copy()->endOfWeek(Carbon::SUNDAY)->utc()])
                ->count(),
            // Signal passif §4.3 : bandeau admin dès qu'un compte est éligible (tampon J+7 écoulé).
            'eligibleDeletions' => auth()->user()?->isAdmin() ? User::deletionEligible()->count() : 0,
            // Rappel passif §4.5 : dès le 1er sept, tant que la bascule de saison n'a pas été déclenchée.
            'rolloverReminder' => auth()->user()?->isAdmin() ? ClubSettings::current()->needsRolloverReminder() : false,
            // Pages d'info épinglées, filtrées par visibilité selon le REGARDEUR (pas le sujet enfant).
            'infoBanners' => InformationPage::query()
                ->active()
                ->where('pinned', true)
                ->visibleTo(auth()->user())
                ->ordered()
                ->get(),
            // Source unique du calcul d'affichage des quotas : QuotaService (§4.10) — sur le sujet.
            'weekQuotas' => $subject
                ? app(QuotaService::class)->weeklyUsage($subject, $now)
                : [],
            ...(auth()->check() ? $this->subjectViewData() : []),
        ]);
    }
}
