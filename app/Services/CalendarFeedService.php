<?php

namespace App\Services;

use App\Models\CalendarFeed;
use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Support\Ics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Abonnement personnel à l'agenda (#39, PRD §4.21.2) : cycle de vie du jeton et contenu du flux.
 *
 * Le contenu est d'abord réduit à des « entrées » légères (séance, personne concernée, préfixe,
 * provisoire) : leur empreinte sert d'ETag, et le rendu iCalendar n'est calculé que si elle a changé.
 */
class CalendarFeedService
{
    public const JOURS_PASSES = 60;

    public const JOURS_A_VENIR = 365;

    public function active(User $user): ?CalendarFeed
    {
        return CalendarFeed::query()->active()->where('user_id', $user->id)->latest('id')->first();
    }

    /** Crée l'adresse, ou la régénère : l'ancienne est révoquée, les réglages recopiés. */
    public function regenerate(User $user): CalendarFeed
    {
        return DB::transaction(function () use ($user) {
            $ancienne = $this->active($user);
            $this->revoke($user);

            // Sans adresse précédente, les défauts de la table s'appliquent (tout coché, pas de rappel).
            $reglages = $ancienne === null ? []
                : $ancienne->only(['include_waitlist', 'include_coaching', 'include_wards', 'reminder']);

            return CalendarFeed::create(['user_id' => $user->id, 'token' => CalendarFeed::newToken()] + $reglages)->refresh();
        });
    }

    public function revoke(User $user): void
    {
        CalendarFeed::query()->active()->where('user_id', $user->id)->update(['revoked_at' => Carbon::now()]);
    }

    /** Accès athlète suspendu, compte désactivé (suppression demandée) ou anonymisé : flux fermé. */
    public function isClosedFor(User $user): bool
    {
        return ! $user->is_active || $user->athlete_access_suspended || $user->anonymized_at !== null;
    }

    public function hasWards(User $user): bool
    {
        return $user->wards()->whereNull('anonymized_at')->exists();
    }

    /**
     * Entrées du flux, une par (séance, personne concernée).
     *
     * @return list<array{session: Session, pour: User, prefixe: string, provisoire: bool}>
     */
    public function entries(CalendarFeed $feed): array
    {
        $user = $feed->user;
        $debut = Carbon::now()->subDays(self::JOURS_PASSES);
        $fin = Carbon::now()->addDays(self::JOURS_A_VENIR);
        $entrees = [];

        // Séances encadrées d'abord : si le coach y est aussi inscrit, la version « Coach — » l'emporte
        // (même UID, un seul événement).
        if ($feed->include_coaching && $user->hasRole('coach')) {
            $user->coachSessions()->with('location')->whereBetween('start_at', [$debut, $fin])->get()
                ->each(function (Session $s) use (&$entrees, $user) {
                    $entrees["{$s->id}-{$user->id}"] = ['session' => $s, 'pour' => $user, 'prefixe' => 'Coach — ', 'provisoire' => false];
                });
        }

        $personnes = collect([$user]);
        if ($feed->include_wards) {
            $personnes = $personnes->concat($user->wards()->whereNull('anonymized_at')->get());
        }
        $statuts = $feed->include_waitlist ? ['participating', 'waitlist'] : ['participating'];

        Registration::query()
            ->with(['session.location'])
            ->whereIn('user_id', $personnes->pluck('id'))
            ->whereIn('status', $statuts)
            ->whereHas('session', fn ($q) => $q->whereBetween('start_at', [$debut, $fin]))
            ->get()
            ->each(function (Registration $r) use (&$entrees, $personnes, $user) {
                $pour = $personnes->firstWhere('id', $r->user_id);
                $cle = "{$r->session_id}-{$pour->id}";
                if (isset($entrees[$cle])) {
                    return;
                }
                $attente = $r->status === 'waitlist';
                $prefixe = ($attente ? '⏳ Liste d\'attente — ' : '').($pour->id === $user->id ? '' : $pour->first_name.' — ');
                $entrees[$cle] = ['session' => $r->session, 'pour' => $pour, 'prefixe' => $prefixe, 'provisoire' => $attente];
            });

        $entrees = array_values($entrees);
        usort($entrees, fn ($a, $b) => $a['session']->start_at <=> $b['session']->start_at);

        return $entrees;
    }

    /** Empreinte du flux : change dès qu'une séance, un lieu, un réglage ou la fenêtre change. */
    public function etag(CalendarFeed $feed, array $entrees): string
    {
        $morceaux = array_map(fn ($e) => [
            $e['session']->id, $e['session']->updated_at?->timestamp, $e['session']->location?->updated_at?->timestamp,
            $e['pour']->id, $e['prefixe'], $e['provisoire'],
        ], $entrees);

        return '"'.sha1(json_encode([$feed->id, $feed->updated_at?->timestamp, Carbon::now()->toDateString(), config('app.url'), $morceaux])).'"';
    }

    public function render(CalendarFeed $feed, array $entrees): string
    {
        $rappel = $feed->rappel();
        $evenements = array_map(
            fn ($e) => Ics::event($e['session'], $e['pour'], $e['prefixe'], $rappel, $e['provisoire']),
            $entrees,
        );

        return Ics::calendar($evenements, ClubSettings::current()->name.' — mes séances', 'PT1H');
    }
}
