<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Models\User;
use App\Services\CoachRegistrationService;
use App\Services\RegistrationService;
use Illuminate\Support\Collection;
use RuntimeException;

// Encadrement coach (§4.11) : self-inscription, inscription d'un tiers, retrait (garde dernier
// coach), bascules athlète ⇄ coach (§4.11.5) avec confirmations explicites.
trait ManagesCoaching
{
    /** Sélecteur « Inscrire un coach » (voie 3) ouvert ? */
    public bool $pickingCoach = false;

    /** Confirmation « dernier coach » en attente : ['action' => …, 'coach_id' => …] ou null. */
    public ?array $lastCoachConfirm = null;

    /** Bascule de rôle en attente de confirmation : ['dir' => 'to_coach'|'to_athlete', 'user_id' => …, 'last_coach' => bool, 'need_quota' => bool] ou null. */
    public ?array $flipConfirm = null;

    public function openCoachPicker(): void
    {
        $this->authorize('registerCoach', $this->session);
        $this->pickingCoach = true;
    }

    public function closeCoachPicker(): void
    {
        $this->pickingCoach = false;
    }

    /** Voie 2 — self-inscription comme encadrant (§4.11.2). */
    public function registerCoachSelf(CoachRegistrationService $service): void
    {
        $me = auth()->user();
        $this->authorize('registerCoach', $this->session);

        $this->runCoachAction(fn () => $service->register($this->session, $me, $me), onAlreadyAthlete: $me->id);
    }

    /** Voie 3 — inscription d'un autre coach par un tiers (§4.11.2). */
    public function registerCoach(CoachRegistrationService $service, int $userId): void
    {
        $this->authorize('registerCoach', $this->session);
        $coach = User::findOrFail($userId);

        $this->pickingCoach = false;
        $this->runCoachAction(fn () => $service->register($this->session, $coach, auth()->user()), onAlreadyAthlete: $userId);
    }

    /** Self-désinscription ou retrait d'un tiers (§4.11.2). $confirmLast = dialog dernier coach validé. */
    public function unregisterCoach(CoachRegistrationService $service, int $userId, bool $confirmLast = false): void
    {
        $this->authorize('unregisterCoach', $this->session);
        $coach = User::findOrFail($userId);

        $this->runCoachAction(function () use ($service, $coach, $confirmLast) {
            $service->unregister($this->session, $coach, auth()->user(), confirmLastCoach: $confirmLast);
        }, onLastCoach: ['action' => 'unregister', 'coach_id' => $userId]);
    }

    /** Annule le dialog « dernier coach ». */
    public function cancelLastCoachConfirm(): void
    {
        $this->lastCoachConfirm = null;
    }

    /**
     * Bascule athlète → coach (§4.11.5 cas 1/4). Demande confirmation explicite avant d'agir :
     * un 1er appel ouvre $flipConfirm, le bouton de confirmation rappelle avec $confirm=true.
     */
    public function flipToCoach(CoachRegistrationService $service, int $userId, bool $confirm = false): void
    {
        $this->authorize('registerCoach', $this->session);
        $target = User::findOrFail($userId);

        if (! $confirm) {
            $this->flipConfirm = ['dir' => 'to_coach', 'user_id' => $userId];

            return;
        }

        $this->flipConfirm = null;
        $this->runCoachAction(fn () => $service->flipToCoach($this->session, $target, auth()->user()));
    }

    /**
     * Bascule coach → athlète (§4.11.5 cas 2/3). Confirmation explicite + garde dernier coach +
     * confirmation quota éventuelle (si l'inscription athlète déborde le quota).
     */
    public function flipToAthlete(CoachRegistrationService $service, int $userId, bool $confirm = false, bool $confirmLast = false, bool $confirmQuota = false): void
    {
        $this->authorize('unregisterCoach', $this->session);
        $target = User::findOrFail($userId);

        if (! $confirm) {
            $this->flipConfirm = [
                'dir' => 'to_athlete',
                'user_id' => $userId,
                'last_coach' => $service->isLastCoach($this->session, $target),
                'need_quota' => false,
            ];

            return;
        }

        try {
            $service->flipToAthlete($this->session, $target, auth()->user(), confirmLastCoach: $confirmLast, confirmQuota: $confirmQuota);
            $this->flipConfirm = null;
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                // L'inscription athlète résultante déborde le quota → on redemande confirmation.
                $this->flipConfirm['need_quota'] = true;
            } elseif ($e->getMessage() === CoachRegistrationService::LAST_COACH_NEEDS_CONFIRM) {
                $this->flipConfirm['last_coach'] = true;
            } else {
                session()->flash('warn', $this->translateRegError($e->getMessage()));
                $this->flipConfirm = null;
            }
        }

        $this->refreshSession();
    }

    public function cancelFlip(): void
    {
        $this->flipConfirm = null;
    }

    /**
     * Exécute une action d'encadrement en traduisant LAST_COACH_NEEDS_CONFIRM en dialog
     * (sinon flash l'erreur), puis recharge la séance.
     *
     * @param  array{action:string,coach_id:int}|null  $onLastCoach
     * @param  int|null  $onAlreadyAthlete  id à basculer si la cible est déjà inscrite athlète
     *                                      (ALREADY_ATHLETE → ouvre le dialog flipToCoach §4.11.5).
     */
    private function runCoachAction(callable $fn, ?array $onLastCoach = null, ?int $onAlreadyAthlete = null): void
    {
        try {
            $fn();
            $this->lastCoachConfirm = null;
        } catch (RuntimeException $e) {
            if ($e->getMessage() === CoachRegistrationService::LAST_COACH_NEEDS_CONFIRM && $onLastCoach) {
                $this->lastCoachConfirm = $onLastCoach;
            } elseif ($e->getMessage() === CoachRegistrationService::ALREADY_ATHLETE && $onAlreadyAthlete !== null) {
                // Exclusivité : on ne crée pas de double-statut, on propose la bascule athlète→coach.
                $this->flipConfirm = ['dir' => 'to_coach', 'user_id' => $onAlreadyAthlete];
            } else {
                session()->flash('warn', $e->getMessage());
            }
        }

        $this->refreshSession();
    }

    /**
     * Coachs inscriptibles (voie 3) : rôle coach, actifs, pas déjà encadrants (§4.11.2/.6).
     *
     * @return Collection<int, User>
     */
    private function selectableCoaches()
    {
        $alreadyIn = $this->session->coaches->pluck('id')->all();
        // Exclusivité (§2/§4.11.5) : un athlète déjà inscrit n'apparaît pas dans le picker coach — sa
        // bascule passe par flipToCoach (annule l'inscription) depuis l'onglet Encadrement / Inscrits.
        $registered = $this->session->registrations
            ->whereIn('status', ['participating', 'waitlist'])
            ->pluck('user_id')->all();

        return User::query()
            ->with('qualifications')
            ->where('is_active', true)
            ->whereJsonContains('roles', 'coach')
            ->whereNotIn('id', array_merge($alreadyIn, $registered))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }
}
