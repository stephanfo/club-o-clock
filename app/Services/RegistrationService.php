<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Cœur des inscriptions (PRD §4.9, §4.10). Toute mutation passe ici : verrou pessimiste +
// transaction garantissent la sérialisation atomique (§4.9.5) — pas de double-acceptation.
// Quota fair-share + files `capacity`/`quota_exceeded` + mécanismes A/B/C + override (J3).
class RegistrationService
{
    public function __construct(
        private QuotaService $quota,
        private AperoService $apero,
        private NotificationDispatcher $notifier,
    ) {}

    /** Levée quand l'inscription dépasserait le quota et que l'athlète n'a pas confirmé (§4.10.3). */
    public const QUOTA_NEEDS_CONFIRM = 'quota_needs_confirm';

    /**
     * Levée quand la cible n'a pas le rôle athlète : seuls les athlètes s'inscrivent comme tels
     * (§2 rôles). Un coach-pur ne peut donc pas être inscrit athlète — y compris par override ni
     * via la bascule flipToAthlete (qui s'appuie sur register()).
     */
    public const NOT_AN_ATHLETE = 'not_an_athlete';

    /**
     * Levée quand l'accès athlète de la cible est suspendu (§4.4) : l'inscription normale est
     * bloquée quel que soit l'acteur (self, parent, coach/admin). Seul l'override §4.10.5 outrepasse.
     */
    public const SUSPENDED = 'suspended';

    /**
     * Levée quand la cible s'inscrit (self / parent→enfant) à une séance hors de ses catégories, ou
     * n'a aucune catégorie active (§4.5). Rempart serveur derrière la garde SessionPolicy::enroll —
     * la fiche est atteignable par URL directe. NON appliquée au staff (§4.9.7 : le bureau inscrit
     * qui il veut) ni à une inscription active déjà en place (grandfathered §4.5 l.262).
     */
    public const CATEGORY_MISMATCH = 'category_mismatch';

    /**
     * Traduit une sentinelle d'inscription en message utilisateur (sinon renvoie le message tel
     * quel — les autres RuntimeException du service portent déjà un libellé français). Utilisée
     * par les composants qui flashent l'erreur (SessionShow, Planning).
     */
    public static function userMessage(string $message): string
    {
        return match ($message) {
            self::NOT_AN_ATHLETE => "Cet utilisateur n'a pas le rôle athlète : il ne peut pas être inscrit comme athlète.",
            self::SUSPENDED => 'Accès athlète suspendu : inscription impossible (réactivation via l\'admin).',
            self::CATEGORY_MISMATCH => 'Inscription impossible : la séance ne cible pas une catégorie active de l\'athlète.',
            default => $message,
        };
    }

    /**
     * Inscrit $target sur $session (action menée par $actor : soi, parent, ou coach).
     * Applique l'algo quota (§4.10.3) : sous quota → file `capacity` ; au-dessus → file
     * `quota_exceeded` (après confirmation explicite, sinon QUOTA_NEEDS_CONFIRM est levé).
     *
     * @param  bool  $confirmQuota  l'athlète a accepté de partir en `quota_exceeded`.
     */
    public function register(Session $session, User $target, User $actor, bool $confirmQuota = false): Registration
    {
        // Inscription tierce par le bureau (§4.9.7) : actor ≠ target ET actor coach/admin (≠ parent
        // inscrivant son enfant — un coach-parent agissant pour SON enfant reste un parent).
        // La cible en est notifiée APRÈS commit (notif hors transaction).
        $byStaff = $actor->id !== $target->id
            && $target->guardian_id !== $actor->id
            && ($actor->hasRole('coach') || $actor->hasRole('admin'));

        $registration = DB::transaction(function () use ($session, $target, $actor, $confirmQuota, $byStaff) {
            // Re-fetch verrouillé : sérialise les inscriptions concurrentes sur la même séance (§4.9.5).
            $locked = Session::query()->with(['quotaTag', 'categories'])->lockForUpdate()->findOrFail($session->getKey());

            if ($locked->hasStarted()) {
                throw new RuntimeException('Inscriptions fermées : la séance a commencé.');
            }
            if ($locked->isCancelled()) {
                throw new RuntimeException('Séance annulée.');
            }

            // Seul un athlète s'inscrit comme athlète (§2). Bloque l'inscription d'un coach-pur, y
            // compris via flipToAthlete (qui appelle register()).
            if (! $target->hasRole('athlete')) {
                throw new RuntimeException(self::NOT_AN_ATHLETE);
            }

            // §4.4 : accès athlète suspendu = inscription bloquée sur les 3 kind, y compris par le
            // bureau (§4.9.7). La policy couvre self/parent ; cette garde couvre le chemin staff.
            if ($target->athlete_access_suspended) {
                throw new RuntimeException(self::SUSPENDED);
            }

            $existing = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();

            // Déjà activement inscrit (participating/waitlist) → idempotent. Sert aussi de
            // grandfathering (§4.5 l.262) : une inscription active existante échappe à la garde
            // catégorielle ci-dessous (re-inscription après annulation d'une séance elle-même
            // dé-ciblée depuis).
            if ($existing && $existing->status !== 'cancelled') {
                return $existing;
            }

            // §4.5 défense en profondeur : le self-service (self / parent→enfant) ne peut inscrire
            // que sur une séance ciblant une catégorie active de la cible. Le staff (§4.9.7) est
            // épargné — il inscrit qui il veut. L'override §4.10.5 passe par overrideRegister().
            if (! $byStaff && ! ($target->hasActiveCategory() && $target->isTargetedBy($locked))) {
                throw new RuntimeException(self::CATEGORY_MISMATCH);
            }

            $participating = Registration::query()
                ->where('session_id', $locked->id)
                ->where('status', 'participating')
                ->count();

            $full = $locked->capacity !== null && $participating >= $locked->capacity;
            $overQuota = $this->quota->isOverQuota($target, $locked, excludeSessionId: $locked->id);

            // Algo §4.10.3 : le quota prime sur la capacité pour le motif de waitlist.
            if ($overQuota) {
                if (! $confirmQuota) {
                    throw new RuntimeException(self::QUOTA_NEEDS_CONFIRM);
                }
                $status = 'waitlist';
                $reason = 'quota_exceeded';
            } elseif ($full) {
                $status = 'waitlist';
                $reason = 'capacity';
            } else {
                $status = 'participating';
                $reason = null;
            }

            $registration = $this->persist($existing, $locked, $target, [
                'status' => $status,
                'waitlist_reason' => $reason,
                'registered_at' => Carbon::now(),
                'promoted_at' => null,
                'promoted_by' => null,
                'override_by' => null,
                'override_reason' => null,
            ]);

            // §4.9.7 : inscription_by_coach quand un coach/admin inscrit un tiers ; inscription_for_other
            // pour le parent inscrivant son enfant ; inscription pour soi-même.
            $action = match (true) {
                $actor->id === $target->id => 'inscription',
                $byStaff => 'inscription_by_coach',
                default => 'inscription_for_other',
            };
            ActivityLogger::record($action, $actor, [
                'user_id' => $target->id,
                'session_id' => $locked->id,
                'registration_id' => $registration->id,
                'resulting_status' => $status,
            ]);

            return $registration;
        });

        // §4.9.7 « Notif à l'athlète » : la cible inscrite par le bureau est prévenue (push + email).
        // Distinct de l'override (§4.10.5) qui émet CoachOverride depuis overrideRegister().
        if ($byStaff) {
            $this->notifier->dispatch(NotificationType::EnrolledByCoach, $target, ['session_id' => $session->id]);
        }

        return $registration;
    }

    /**
     * Override coach (§4.10.5) : force `participating` en outrepassant quota, capacité et/ou
     * suspension. Compte dans le quota de l'athlète. AuditLog `override_quota`.
     */
    public function overrideRegister(Session $session, User $target, User $coach, ?string $motif = null): Registration
    {
        $registration = DB::transaction(function () use ($session, $target, $coach, $motif) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($locked->hasStarted()) {
                throw new RuntimeException('Inscriptions fermées : la séance a commencé.');
            }

            // L'override outrepasse quota/capacité/suspension (§4.10.5) mais PAS le rôle : un coach-pur
            // n'a aucune existence « athlète » à forcer (§2). Garde stricte, même pour le bureau.
            if (! $target->hasRole('athlete')) {
                throw new RuntimeException(self::NOT_AN_ATHLETE);
            }

            $existing = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();

            $registration = $this->persist($existing, $locked, $target, [
                'status' => 'participating',
                'waitlist_reason' => null,
                'registered_at' => $existing && $existing->registered_at ? $existing->registered_at : Carbon::now(),
                'promoted_at' => null,
                'promoted_by' => null,
                'override_by' => $coach->id,
                'override_reason' => $motif,
            ]);

            AuditLogger::record('override_quota', $coach, [
                'target_type' => User::class,
                'target_id' => $target->id,
                'session_id' => $locked->id,
                'motif' => $motif,
            ]);
            ActivityLogger::record('inscription_override', $coach, [
                'user_id' => $target->id,
                'session_id' => $locked->id,
                'registration_id' => $registration->id,
                'resulting_status' => 'participating',
            ]);

            return $registration;
        });

        // Après commit : l'athlète inscrit d'office est prévenu (§4.10.5, type coach_override).
        $this->notifier->dispatch(NotificationType::CoachOverride, $target, ['session_id' => $session->id]);

        return $registration;
    }

    /**
     * Mécanisme C (§4.10.4) : déblocage manuel coach. Promeut autant d'athlètes de
     * `quota_exceeded` (FIFO) qu'il reste de places. Précondition : file `capacity` vide
     * ET places restantes. Une entrée AuditLog `promote_quota_exceeded` par athlète promu.
     *
     * @return int nombre d'athlètes promus.
     */
    public function fillFromQuotaExceeded(Session $session, User $coach, ?string $motif = null): int
    {
        $promoted = [];

        $count = DB::transaction(function () use ($session, $coach, $motif, &$promoted) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            $hasCapacityQueue = Registration::query()
                ->where('session_id', $locked->id)
                ->where('status', 'waitlist')->where('waitlist_reason', 'capacity')
                ->exists();

            if ($hasCapacityQueue) {
                throw new RuntimeException('Vide d\'abord la file « séance pleine » avant de débloquer le quota.');
            }

            $seats = $this->freeSeats($locked);
            if ($seats <= 0) {
                return 0;
            }

            $candidates = Registration::query()
                ->where('session_id', $locked->id)
                ->where('status', 'waitlist')->where('waitlist_reason', 'quota_exceeded')
                ->orderBy('registered_at')
                ->lockForUpdate()
                ->limit($seats)
                ->get();

            foreach ($candidates as $reg) {
                $reg->update([
                    'status' => 'participating',
                    'waitlist_reason' => null,
                    'promoted_at' => Carbon::now(),
                    'promoted_by' => $coach->id,
                ]);

                AuditLogger::record('promote_quota_exceeded', $coach, [
                    'target_type' => User::class,
                    'target_id' => $reg->user_id,
                    'session_id' => $locked->id,
                    'motif' => $motif,
                ]);
                ActivityLogger::record('promote_quota_exceeded', $coach, [
                    'user_id' => $reg->user_id,
                    'session_id' => $locked->id,
                    'registration_id' => $reg->id,
                    'resulting_status' => 'participating',
                ]);

                $promoted[] = $reg;
            }

            return $candidates->count();
        });

        $this->notifyPromoted($promoted);

        return $count;
    }

    /** Crée ou réactive la ligne d'inscription (unique session,user). */
    private function persist(?Registration $existing, Session $session, User $target, array $attrs): Registration
    {
        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing;
        }

        return Registration::create([
            'session_id' => $session->id,
            'user_id' => $target->id,
            ...$attrs,
        ]);
    }

    /**
     * Annulation de séance (§4.10.6) : la séance est déjà flaggée `cancelled_at` par l'appelant,
     * donc elle ne compte plus dans le quota (cf. QuotaService). Les inscriptions sont CONSERVÉES
     * telles quelles (réversibilité de la restauration §4.7) ; on déclenche seulement le mécanisme B
     * pour chaque `participating` afin de promouvoir aussitôt ses `quota_exceeded` desserrés ailleurs.
     */
    public function onSessionCancelled(Session $session): void
    {
        $promoted = [];

        DB::transaction(function () use ($session, &$promoted) {
            $participants = Registration::query()
                ->where('session_id', $session->id)
                ->where('status', 'participating')
                ->with('user')
                ->get();

            foreach ($participants as $reg) {
                if ($reg->user) {
                    $this->releaseOwnQuota($session, $reg->user, $promoted);
                }
            }
        });

        $this->notifyPromoted($promoted);
    }

    /** Places libres (négatif possible en surcapacité override) ; null capacity = illimité. */
    private function freeSeats(Session $session): int
    {
        if ($session->capacity === null) {
            return PHP_INT_MAX;
        }

        $participating = Registration::query()
            ->where('session_id', $session->id)
            ->where('status', 'participating')
            ->count();

        return $session->capacity - $participating;
    }

    /**
     * Désinscrit $target ; si une place `participating` se libère, promeut le 1er de la
     * file `capacity` (FIFO) dans la même transaction — promotion synchrone (§4.9.3).
     */
    public function cancel(Session $session, User $target, User $actor): void
    {
        $promoted = [];

        DB::transaction(function () use ($session, $target, $actor, &$promoted) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($locked->hasStarted()) {
                throw new RuntimeException('Désinscription fermée : la séance a commencé.');
            }

            $registration = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $target->id)
                ->whereIn('status', ['participating', 'waitlist'])
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                return; // rien à désinscrire (idempotent).
            }

            $freedSeat = $registration->status === 'participating';
            $registration->update(['status' => 'cancelled']);

            // Voie 3 (§4.14.4) : la perte de l'inscription active cascade sur le flag apéro.
            $this->apero->cascadeOnRegistrationLoss($locked, $target);

            $action = $actor->id === $target->id ? 'desinscription' : 'desinscription_for_other';
            ActivityLogger::record($action, $actor, [
                'user_id' => $target->id,
                'session_id' => $locked->id,
                'registration_id' => $registration->id,
                'resulting_status' => 'cancelled',
            ]);

            if ($freedSeat) {
                // Mécanisme A : promeut le 1er de la file capacity de CETTE séance.
                $this->promoteNext($locked, $promoted);
                // Mécanisme B : libère le propre quota de l'athlète sur les AUTRES séances du même tag.
                $this->releaseOwnQuota($locked, $target, $promoted);
            }
        });

        $this->notifyPromoted($promoted);
    }

    /**
     * Annulation système d'une inscription future (bascule de saison §4.4). Soft-flag `cancelled`,
     * `ActivityLog registration_cancelled` avec actorId = system, puis déclenche mécanisme A
     * (promotion file capacity) + B (libération du propre quota). No-op (false) si rien à annuler
     * ou si la séance a déjà commencé. Renvoie true si une inscription a été annulée.
     */
    public function cancelAsSystem(Session $session, User $target): bool
    {
        $promoted = [];

        $cancelled = DB::transaction(function () use ($session, $target, &$promoted) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($locked->hasStarted() || $locked->cancelled_at !== null) {
                return false;
            }

            $registration = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $target->id)
                ->whereIn('status', ['participating', 'waitlist'])
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                return false;
            }

            $freedSeat = $registration->status === 'participating';
            $registration->update(['status' => 'cancelled']);

            // Voie 3 (§4.14.4) : cascade système sur le flag apéro (bascule de saison, etc.).
            $this->apero->cascadeOnRegistrationLoss($locked, $target);

            ActivityLogger::system('registration_cancelled', [
                'user_id' => $target->id,
                'session_id' => $locked->id,
                'registration_id' => $registration->id,
                'resulting_status' => 'cancelled',
            ]);

            if ($freedSeat) {
                $this->promoteNext($locked, $promoted);
                $this->releaseOwnQuota($locked, $target, $promoted);
            }

            return true;
        });

        $this->notifyPromoted($promoted);

        return $cancelled;
    }

    /**
     * Promeut le 1er de la file `capacity` (FIFO registered_at ASC). Appelé sous verrou.
     * Le promu est collecté dans $promoted pour la notif `waitlist_promoted` (émise après commit).
     *
     * @param  list<Registration>  $promoted
     */
    private function promoteNext(Session $session, array &$promoted): void
    {
        $next = Registration::query()
            ->where('session_id', $session->id)
            ->where('status', 'waitlist')
            ->where('waitlist_reason', 'capacity')
            ->orderBy('registered_at')
            ->lockForUpdate()
            ->first();

        if (! $next) {
            return;
        }

        $next->update([
            'status' => 'participating',
            'waitlist_reason' => null,
            'promoted_at' => Carbon::now(),
            'promoted_by' => null, // promotion automatique = système.
        ]);

        ActivityLogger::system('waitlist_promoted', [
            'user_id' => $next->user_id,
            'session_id' => $session->id,
            'registration_id' => $next->id,
            'resulting_status' => 'participating',
        ]);

        $promoted[] = $next;
    }

    /**
     * Mécanisme B (§4.10.4) : quand $user libère une place sur une séance taguée T, son quota T
     * de la semaine se desserre. Pour chacune de ses inscriptions `waitlist quota_exceeded` sur
     * une AUTRE séance du même tag dans la même semaine :
     *   (a) capacité dispo → bascule en `participating` ;
     *   (b) capacité pleine → migre `quota_exceeded` → `capacity` (conserve registered_at, FIFO).
     * Appelé sous verrou, dans la transaction de cancel(). Seules les bascules (a) — passage réel à
     * `participating` — sont collectées dans $promoted (la migration (b) reste en file d'attente).
     *
     * @param  list<Registration>  $promoted
     */
    private function releaseOwnQuota(Session $freed, User $user, array &$promoted): void
    {
        if ($freed->quota_tag_id === null) {
            return; // séance sans tag → pas de quota à libérer.
        }

        [$from, $to] = $this->quota->weekBoundsUtc($freed->start_at);

        $blocked = Registration::query()
            ->where('user_id', $user->id)
            ->where('status', 'waitlist')->where('waitlist_reason', 'quota_exceeded')
            ->where('session_id', '!=', $freed->id)
            ->whereHas('session', fn ($q) => $q
                ->where('quota_tag_id', $freed->quota_tag_id)
                ->whereBetween('start_at', [$from, $to]))
            ->with('session')
            ->orderBy('registered_at')
            ->lockForUpdate()
            ->get();

        foreach ($blocked as $reg) {
            // Le quota n'est tenable que pour une inscription par unité libérée : on s'arrête
            // dès que l'athlète repasse au-dessus du quota (recalcul à chaque itération).
            if ($this->quota->isOverQuota($user, $reg->session, excludeSessionId: $reg->session_id)) {
                break;
            }

            $otherFull = $reg->session->capacity !== null
                && Registration::query()->where('session_id', $reg->session_id)
                    ->where('status', 'participating')->count() >= $reg->session->capacity;

            if ($otherFull) {
                // Cas (b) : migration vers la file capacity, registered_at conservé.
                $reg->update(['waitlist_reason' => 'capacity']);
                $resulting = 'waitlist_capacity';
            } else {
                // Cas (a) : confirmation directe.
                $reg->update([
                    'status' => 'participating',
                    'waitlist_reason' => null,
                    'promoted_at' => Carbon::now(),
                    'promoted_by' => null,
                ]);
                $resulting = 'participating';
                $promoted[] = $reg;
            }

            ActivityLogger::system('auto_promoted_self_quota', [
                'user_id' => $user->id,
                'session_id' => $reg->session_id,
                'registration_id' => $reg->id,
                'resulting_status' => $resulting,
            ]);
        }
    }

    /**
     * Émet `waitlist_promoted` (§4.10.4) à chaque athlète passé en `participating`. Appelé APRÈS
     * commit : les lignes d'outbox ne sont créées qu'une fois la promotion réellement persistée.
     *
     * @param  list<Registration>  $promoted
     */
    /**
     * Mécanisme A étendu (E2) : après une hausse de capacité, promeut en FIFO tous les athlètes
     * en file `capacity` tant que des places restent disponibles. Appelé par SessionForm::persist().
     * Ne touche pas la file `quota_exceeded` (mécanisme C, déblocage manuel uniquement).
     */
    public function onCapacityIncreased(Session $session): void
    {
        $promoted = [];

        DB::transaction(function () use ($session, &$promoted) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($locked->capacity === null) {
                return; // capacité illimitée — rien à promouvoir.
            }

            $currentParticipants = Registration::query()
                ->where('session_id', $locked->id)
                ->where('status', 'participating')
                ->count();

            $available = $locked->capacity - $currentParticipants;

            for ($i = 0; $i < $available; $i++) {
                $before = count($promoted);
                $this->promoteNext($locked, $promoted);
                if (count($promoted) === $before) {
                    break; // file capacity vide.
                }
            }
        });

        $this->notifyPromoted($promoted);
    }

    private function notifyPromoted(array $promoted): void
    {
        foreach ($promoted as $reg) {
            // Les promus viennent de requêtes hétérogènes (waitlist, quota_exceeded, autres
            // séances) qui ne préchargent pas toutes user → chargement explicite si absent.
            $reg->loadMissing('user');
            if ($reg->user !== null) {
                $this->notifier->dispatch(
                    NotificationType::WaitlistPromoted,
                    $reg->user,
                    ['session_id' => $reg->session_id],
                );
            }
        }
    }
}
