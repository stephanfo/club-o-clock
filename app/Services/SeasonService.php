<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Support\AgeCategory;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Bascule de saison (PRD §4.4, §4.5 — J6.4). Deux gestes annuels distincts à l'initiative admin :
// suspension de masse des athlètes (accès inscription) et démarrage de nouvelle année sportive
// (recalcul des catégories). Plus la réactivation individuelle. Gestes sensibles → AuditLog global.
class SeasonService
{
    public function __construct(
        private RegistrationService $registrations,
        private NotificationDispatcher $notifier,
    ) {}

    /** Périmètre suspension/recalcul : tout compte non anonymisé ayant le rôle athlete (§4.4). */
    private function athletesQuery()
    {
        return User::query()
            ->whereNull('anonymized_at')
            ->whereJsonContains('roles', 'athlete');
    }

    /** Compteurs d'impact affichés dans les modales de confirmation (sans muter). */
    public function impactCounters(): array
    {
        $athleteIds = $this->athletesQuery()->pluck('id');

        return [
            'athletes' => $athleteIds->count(),
            'future_registrations' => Registration::query()
                ->whereIn('user_id', $athleteIds)
                ->whereIn('status', ['participating', 'waitlist'])
                ->whereHas('session', fn ($q) => $q->whereNull('cancelled_at')->where('start_at', '>', Carbon::now()))
                ->count(),
            'recalculable' => $this->athletesQuery()->whereNotNull('dob')->count(),
            'surclassements' => DB::table('user_category')
                ->whereIn('user_id', $athleteIds)
                ->where('is_primary', false)
                ->count(),
        ];
    }

    /**
     * §4.4 — Suspension de masse pour la nouvelle saison. Pose athleteAccessSuspended sur tous les
     * athlètes (coachs/admins athlètes inclus), annule les inscriptions futures (soft cancel système
     * + promotions auto mécanisme A), trace 1 AuditLog global bulk_athlete_deactivation. isActive
     * non touché : un coach reste coach, un admin reste admin.
     *
     * @return array{accounts:int,registrations:int}
     */
    public function deactivateAllAthletes(User $admin, ?string $motif = null): array
    {
        $athletes = $this->athletesQuery()->get();

        $accounts = 0;
        foreach ($athletes as $athlete) {
            if (! $athlete->athlete_access_suspended) {
                $athlete->update(['athlete_access_suspended' => true]);
                $accounts++;
            }
        }

        // Inscriptions futures (participating/waitlist) sur séances non annulées → annulation système.
        $futureRegs = Registration::query()
            ->whereIn('user_id', $athletes->pluck('id'))
            ->whereIn('status', ['participating', 'waitlist'])
            ->whereHas('session', fn ($q) => $q->whereNull('cancelled_at')->where('start_at', '>', Carbon::now()))
            ->with(['session', 'user'])
            ->get();

        $registrations = 0;
        foreach ($futureRegs as $reg) {
            if ($reg->session && $reg->user && $this->registrations->cancelAsSystem($reg->session, $reg->user)) {
                $registrations++;
            }
        }

        // 1 entrée globale (pas d'entrées individuelles, §4.4). Compteurs + motif dans le champ libre.
        AuditLogger::record('bulk_athlete_deactivation', $admin, [
            'motif' => trim(sprintf('%d comptes · %d inscriptions annulées%s', $accounts, $registrations, $motif ? ' — '.$motif : '')),
        ]);

        return ['accounts' => $accounts, 'registrations' => $registrations];
    }

    /**
     * §4.5 — Démarrage de la nouvelle année sportive. Pour chaque athlète : recalcul de la catégorie
     * principale (date de naissance + année sportive sept→août) et purge des surclassements manuels
     * (UserCategory non-principaux). Inscriptions futures grandfathered (non touchées). 1 transaction,
     * 1 AuditLog global season_rollover. Distinct de la suspension (comptes restent actifs).
     *
     * @return array{recalculated:int,surclassements_removed:int}
     */
    public function startNewSeason(User $admin): array
    {
        return DB::transaction(function () use ($admin) {
            $athletes = $this->athletesQuery()->with('categories')->get();
            $activeCategories = Category::query()->whereNull('archived_at')->get();

            $recalculated = 0;
            $removed = 0;

            foreach ($athletes as $athlete) {
                $removed += $athlete->categories->where('pivot.is_primary', false)->count();

                $primary = $athlete->dob ? AgeCategory::derive($athlete->dob, null, $activeCategories) : null;

                // sync = uniquement la principale recalculée (les surclassements disparaissent).
                $athlete->categories()->sync($primary ? [$primary->id => ['is_primary' => true]] : []);
                $recalculated++;
            }

            AuditLogger::record('season_rollover', $admin, [
                'motif' => sprintf('%d catégories recalculées · %d surclassements effacés', $recalculated, $removed),
            ]);

            ClubSettings::current()->update(['season_rollover_at' => Carbon::now()]);

            return ['recalculated' => $recalculated, 'surclassements_removed' => $removed];
        });
    }

    /**
     * §4.4 — Réactivation individuelle de l'accès athlète. Lève le flag, trace account_activated,
     * et émet un email via le dispatcher (mis en file dans l'outbox, drainé en J8 — §7.14).
     * Les inscriptions précédemment annulées ne sont PAS restaurées. No-op si déjà actif.
     */
    public function reactivateAthlete(User $member, User $admin): void
    {
        DB::transaction(function () use ($member, $admin) {
            if (! $member->athlete_access_suspended || $member->anonymized_at !== null) {
                return;
            }

            $member->update(['athlete_access_suspended' => false]);

            AuditLogger::record('account_activated', $admin, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);

            // Émetteur unique (§7.14) : routage parent/enfant + matrice + pause appliqués au même
            // endroit. Type email seul ; rien n'est mis en file si le destinataire n'a pas d'adresse.
            $this->notifier->dispatch(
                NotificationType::AthleteReactivated,
                $member,
                ['user_id' => $member->id],
            );
        });
    }
}
