<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Encadrement coach (PRD §4.11) : inscription/désinscription comme coach (3 voies), garde
// « dernier coach », bascule athlète ↔ coach (4 cas §4.11.5). Le rôle coach est global — la
// présence dans coaches[] ne confère aucun droit, elle sert à l'affichage, aux qualifs agrégées
// et aux stats (§4.11.2). Concurrence sérialisée par verrou pessimiste sur la séance.
class CoachRegistrationService
{
    public function __construct(
        private RegistrationService $registrations,
        private NotificationDispatcher $notifier,
    ) {}

    /** Levée quand le retrait viderait l'encadrement et que l'appelant n'a pas confirmé (§4.11.2). */
    public const LAST_COACH_NEEDS_CONFIRM = 'last_coach_needs_confirm';

    /**
     * Levée quand on tente d'inscrire comme coach une personne déjà inscrite athlète sur la séance
     * (exclusivité §2 / §4.11.5) : l'appelant doit passer par la bascule flipToCoach (qui annule
     * d'abord l'inscription athlète) plutôt que créer un double-statut.
     */
    public const ALREADY_ATHLETE = 'already_athlete';

    /**
     * Inscrit $coach comme encadrant (voie 2 = self, voie 3 = par un tiers — §4.11.2).
     * Idempotent si déjà inscrit. ActivityLog coach_registered (actorId peut ≠ userId).
     */
    public function register(Session $session, User $coach, User $actor): void
    {
        $coCoachIds = [];
        $registered = false;

        DB::transaction(function () use ($session, $coach, $actor, &$coCoachIds, &$registered) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->guardOpen($locked);
            $this->guardKindTraining($locked);

            if (! $coach->hasRole('coach')) {
                throw new RuntimeException("Cet utilisateur n'a pas le rôle coach.");
            }

            // Idempotent : déjà encadrant.
            if ($locked->coaches()->whereKey($coach->id)->exists()) {
                return;
            }

            // Exclusivité (§2 / §4.11.5) : déjà inscrit athlète → on refuse l'attach silencieux.
            // L'UI rattrape ALREADY_ATHLETE en ouvrant la bascule flipToCoach (annule l'inscription
            // athlète AVANT d'ajouter à coaches[]). Sans ce garde on créerait un double-statut.
            $hasAthleteReg = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $coach->id)
                ->whereIn('status', ['participating', 'waitlist'])
                ->exists();
            if ($hasAthleteReg) {
                throw new RuntimeException(self::ALREADY_ATHLETE);
            }

            // Co-encadrants présents AVANT l'ajout : ils seront prévenus de l'arrivée (coach_registration).
            $coCoachIds = $locked->coaches()->pluck('users.id')->all();

            $locked->coaches()->attach($coach->id);

            ActivityLogger::record('coach_registered', $actor, [
                'user_id' => $coach->id,
                'session_id' => $locked->id,
            ]);

            $registered = true;
        });

        if ($registered) {
            $this->notifyCoachJoined($session, $coach, $actor, $coCoachIds);
        }
    }

    /**
     * Retire $coach de l'encadrement (self ou par un tiers — §4.11.2). La désinscription du
     * DERNIER coach est autorisée mais exige $confirmLastCoach (dialog explicite §4.11.2) →
     * sinon LAST_COACH_NEEDS_CONFIRM. ActivityLog coach_unregistered.
     */
    public function unregister(Session $session, User $coach, User $actor, bool $confirmLastCoach = false): void
    {
        $remainingIds = [];
        $removed = false;

        DB::transaction(function () use ($session, $coach, $actor, $confirmLastCoach, &$remainingIds, &$removed) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->guardOpen($locked);

            if (! $locked->coaches()->whereKey($coach->id)->exists()) {
                return; // pas encadrant → idempotent.
            }

            // Garde « dernier coach » : responsabilité humaine, on bloque seulement sans confirmation.
            if ($this->isLastCoach($locked, $coach) && ! $confirmLastCoach) {
                throw new RuntimeException(self::LAST_COACH_NEEDS_CONFIRM);
            }

            $locked->coaches()->detach($coach->id);

            ActivityLogger::record('coach_unregistered', $actor, [
                'user_id' => $coach->id,
                'session_id' => $locked->id,
            ]);

            // Encadrants restants APRÈS le retrait : prévenus du départ (coach_registration).
            $remainingIds = $locked->coaches()->pluck('users.id')->all();
            $removed = true;
        });

        if ($removed) {
            $this->notifyCoCoaches($remainingIds, $session->id);
        }
    }

    /**
     * Bascule athlète → coach (§4.11.5 cas 1 self / cas 4 tiers). $target est inscrit athlète :
     * son inscription est soft-annulée (libère place + quota → cascades A/B via cancel()) PUIS il
     * est ajouté à coaches[]. AuditLog role_changed. Le tout sous une seule transaction.
     */
    public function flipToCoach(Session $session, User $target, User $actor): void
    {
        $coCoachIds = [];
        $joined = false;

        DB::transaction(function () use ($session, $target, $actor, &$coCoachIds, &$joined) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->guardOpen($locked);
            $this->guardKindTraining($locked);

            if (! $target->hasRole('coach')) {
                throw new RuntimeException("Cet utilisateur n'a pas le rôle coach.");
            }

            // 1. Retire l'inscription athlète → déclenche mécanismes A (promotion capacity)
            //    et B (libération de son propre quota_exceeded ailleurs).
            $this->registrations->cancel($locked, $target, $actor);

            // 2. Ajoute à l'encadrement (idempotent si déjà là).
            if (! $locked->coaches()->whereKey($target->id)->exists()) {
                $coCoachIds = $locked->coaches()->pluck('users.id')->all();
                $locked->coaches()->attach($target->id);
                $joined = true;
            }

            AuditLogger::record('role_changed', $actor, [
                'target_type' => User::class,
                'target_id' => $target->id,
                'session_id' => $locked->id,
                'motif' => 'athlete_to_coach',
            ]);
            ActivityLogger::record('coach_registered', $actor, [
                'user_id' => $target->id,
                'session_id' => $locked->id,
            ]);
        });

        if ($joined) {
            $this->notifyCoachJoined($session, $target, $actor, $coCoachIds);
        }
    }

    /**
     * Bascule coach → athlète (§4.11.5 cas 2 self / cas 3 tiers). $target encadrant : retiré de
     * coaches[] PUIS inscrit comme athlète (§4.9 — statut résultant selon capacité + quota).
     * Le garde dernier coach s'applique (warning au dialog, pas un blocage : $confirmLastCoach).
     *
     * @return string statut résultant de l'inscription athlète (participating | waitlist).
     */
    public function flipToAthlete(Session $session, User $target, User $actor, bool $confirmLastCoach = false, bool $confirmQuota = false): string
    {
        $remainingIds = [];

        $status = DB::transaction(function () use ($session, $target, $actor, $confirmLastCoach, $confirmQuota, &$remainingIds) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->guardOpen($locked);
            // Cohérence avec register() et flipToCoach : l'encadrement est une notion training
            // (§4.11). L'UI ne l'expose pas ailleurs, c'est un durcissement de la garde serveur.
            $this->guardKindTraining($locked);

            if (! $locked->coaches()->whereKey($target->id)->exists()) {
                throw new RuntimeException("Cet utilisateur n'est pas encadrant sur cette séance.");
            }

            // Bascule réservée aux personnes qui PEUVENT être athlètes (§2) : un coach-pur n'a pas de
            // rôle athlète à activer. On bloque AVANT de le retirer de l'encadrement (sinon on viderait
            // coaches[] pour rien). Source de vérité = register() ; ce check ne fait qu'anticiper le
            // message et éviter le détach inutile.
            if (! $target->hasRole('athlete')) {
                throw new RuntimeException(RegistrationService::NOT_AN_ATHLETE);
            }

            if ($this->isLastCoach($locked, $target) && ! $confirmLastCoach) {
                throw new RuntimeException(self::LAST_COACH_NEEDS_CONFIRM);
            }

            // 1. Retire de l'encadrement.
            $locked->coaches()->detach($target->id);
            $remainingIds = $locked->coaches()->pluck('users.id')->all();

            // Même journal d'activité que unregister() : une bascule est aussi un retrait
            // d'encadrement, elle ne doit pas disparaître du journal (symétrique de flipToCoach,
            // qui émet bien coach_registered).
            ActivityLogger::record('coach_unregistered', $actor, [
                'user_id' => $target->id,
                'session_id' => $locked->id,
            ]);

            AuditLogger::record('role_changed', $actor, [
                'target_type' => User::class,
                'target_id' => $target->id,
                'session_id' => $locked->id,
                'motif' => 'coach_to_athlete',
            ]);

            // 2. Inscrit comme athlète — peut lever QUOTA_NEEDS_CONFIRM (remonte tel quel à l'UI).
            $registration = $this->registrations->register($locked, $target, $actor, confirmQuota: $confirmQuota);

            return $registration->status;
        });

        // Le coach a quitté l'encadrement → les restants sont prévenus (coach_registration).
        $this->notifyCoCoaches($remainingIds, $session->id);

        return $status;
    }

    /** Dernier encadrant inscrit ? (utilisé pour le garde-fou §4.11.2/.5). */
    public function isLastCoach(Session $session, User $coach): bool
    {
        return $session->coaches()->whereKeyNot($coach->id)->doesntExist();
    }

    /**
     * Notifs d'arrivée d'un coach (§4.15.2), émises APRÈS commit :
     *   coach_assigned    → au coach affecté, sauf s'il s'est inscrit lui-même (déjà au courant) ;
     *   coach_registration → aux co-encadrants déjà présents (un coach a rejoint la séance).
     *
     * @param  list<int>  $coCoachIds
     */
    private function notifyCoachJoined(Session $session, User $coach, User $actor, array $coCoachIds): void
    {
        if ($actor->id !== $coach->id) {
            $this->notifier->dispatch(NotificationType::CoachAssigned, $coach, ['session_id' => $session->id]);
        }

        $this->notifyCoCoaches($coCoachIds, $session->id);
    }

    /**
     * Émet coach_registration (« un coach rejoint ou quitte une séance que tu encadres ») à chaque
     * encadrant de la liste. Appelé APRÈS commit, pour une arrivée comme pour un départ.
     *
     * @param  list<int>  $coachIds
     */
    private function notifyCoCoaches(array $coachIds, int $sessionId): void
    {
        if ($coachIds === []) {
            return;
        }

        foreach (User::whereIn('id', $coachIds)->get() as $coCoach) {
            $this->notifier->dispatch(NotificationType::CoachRegistration, $coCoach, ['session_id' => $sessionId]);
        }
    }

    private function guardOpen(Session $session): void
    {
        if ($session->hasStarted()) {
            throw new RuntimeException('Séance commencée — encadrement figé.');
        }
        if ($session->isCancelled()) {
            throw new RuntimeException('Séance annulée.');
        }
    }

    /** Inscription coach structurée = training uniquement (§4.11 périmètre). */
    private function guardKindTraining(Session $session): void
    {
        if ($session->kind !== 'training') {
            throw new RuntimeException("L'inscription coach ne s'applique qu'aux entraînements.");
        }
    }
}
