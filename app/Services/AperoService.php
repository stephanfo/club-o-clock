<?php

namespace App\Services;

use App\Models\AperoFlag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Support\Logging\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Flag « j'offre l'apéro » (PRD §4.14). Pose/retrait du geste personnel + 3 voies de retrait
// (self, modération coach/admin, cascade système sur perte d'inscription active) + park/restore
// à l'annulation/restauration de séance. Aucune notification (§4.14.6). Traçabilité ActivityLog
// apero_flagged / apero_unflagged (§4.14.7) — le motif vit sur l'entité, pas dans le journal.
class AperoService
{
    /**
     * Pose le flag du payeur $user sur $session (geste personnel, jamais par procuration §4.14.1).
     * Précondition : $user a une Registration `participating` et la séance n'a pas commencé.
     * Idempotent si un flag actif existe déjà.
     */
    public function flag(Session $session, User $user, ?string $motif = null): AperoFlag
    {
        return DB::transaction(function () use ($session, $user, $motif) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->guardWindow($locked);

            $registration = Registration::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $user->id)
                ->where('status', 'participating')
                ->first();

            if (! $registration) {
                throw new RuntimeException('Seuls les inscrits actifs peuvent offrir l\'apéro.');
            }

            // Idempotent : flag actif déjà posé.
            $existing = AperoFlag::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing && $existing->parked_at === null) {
                return $existing;
            }

            $motif = $this->normalizeMotif($motif);

            // Un flag garé sur la même (séance,user) ne devrait pas exister hors séance annulée ;
            // on le réécrit défensivement plutôt que de heurter l'unicité (session_id,user_id).
            if ($existing) {
                $existing->forceFill([
                    'registration_id' => $registration->id,
                    'motif' => $motif,
                    'flagged_at' => Carbon::now(),
                    'flagged_by' => $user->id,
                    'parked_at' => null,
                ])->save();
                $flag = $existing;
            } else {
                $flag = AperoFlag::create([
                    'session_id' => $locked->id,
                    'user_id' => $user->id,
                    'registration_id' => $registration->id,
                    'motif' => $motif,
                    'flagged_at' => Carbon::now(),
                    'flagged_by' => $user->id,
                ]);
            }

            ActivityLogger::record('apero_flagged', $user, [
                'user_id' => $user->id,
                'session_id' => $locked->id,
                'registration_id' => $registration->id,
            ]);

            return $flag;
        });
    }

    /**
     * Retire le flag du payeur — voie 1 (self, $actor == $payer) ou voie 2 (modération coach/admin).
     * Fenêtre identique au (dé)flag : bloqué après le début (§4.14.3). Hard delete. Idempotent.
     */
    public function unflag(Session $session, User $payer, User $actor): void
    {
        DB::transaction(function () use ($session, $payer, $actor) {
            $locked = Session::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->guardWindow($locked);

            $flag = AperoFlag::query()
                ->where('session_id', $locked->id)
                ->where('user_id', $payer->id)
                ->whereNull('parked_at')
                ->first();

            if (! $flag) {
                return; // rien à retirer (idempotent).
            }

            $registrationId = $flag->registration_id;
            $flag->delete();

            ActivityLogger::record('apero_unflagged', $actor, [
                'user_id' => $payer->id,
                'session_id' => $locked->id,
                'registration_id' => $registrationId,
            ]);
        });
    }

    /**
     * Voie 3 — cascade système sur perte d'inscription active (désinscription, role-flip,
     * bascule de saison). Appelée DANS la transaction du service d'inscription (pas de re-verrou).
     * Hard delete du flag de $user sur $session ; trace apero_unflagged système si le flag était actif.
     */
    public function cascadeOnRegistrationLoss(Session $session, User $user): void
    {
        $flag = AperoFlag::query()
            ->where('session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $flag) {
            return;
        }

        $wasActive = $flag->parked_at === null;
        $registrationId = $flag->registration_id;
        $flag->delete();

        // Un flag déjà garé (séance annulée) a déjà été tracé au moment du park → pas de double trace.
        if ($wasActive) {
            ActivityLogger::system('apero_unflagged', [
                'user_id' => $user->id,
                'session_id' => $session->id,
                'registration_id' => $registrationId,
            ]);
        }
    }

    /**
     * Annulation de séance (§4.14.4) : tous les flags actifs sont « garés » (réversible à la
     * restauration), pas supprimés — pour conserver le motif. Trace apero_unflagged système par flag.
     * Appelée DANS la transaction d'annulation de séance.
     */
    public function cascadeOnSessionCancel(Session $session): void
    {
        $flags = AperoFlag::query()
            ->where('session_id', $session->id)
            ->whereNull('parked_at')
            ->get();

        foreach ($flags as $flag) {
            $flag->forceFill(['parked_at' => Carbon::now()])->save();

            ActivityLogger::system('apero_unflagged', [
                'user_id' => $flag->user_id,
                'session_id' => $session->id,
                'registration_id' => $flag->registration_id,
            ]);
        }
    }

    /**
     * Restauration de séance (§4.14.4) : les flags garés sont restaurés SI l'inscription est
     * toujours `participating` (sinon l'athlète a perdu son inscription entre-temps → pas de
     * restauration, hard delete). Trace apero_flagged système. Appelée DANS la transaction de restauration.
     */
    public function restoreOnSessionUncancel(Session $session): void
    {
        $flags = AperoFlag::query()
            ->where('session_id', $session->id)
            ->whereNotNull('parked_at')
            ->get();

        foreach ($flags as $flag) {
            $stillActive = Registration::query()
                ->whereKey($flag->registration_id)
                ->where('status', 'participating')
                ->exists();

            if (! $stillActive) {
                $flag->delete(); // inscription perdue entre-temps → non restauré (§4.14.4).

                continue;
            }

            $flag->forceFill(['parked_at' => null])->save();

            ActivityLogger::system('apero_flagged', [
                'user_id' => $flag->user_id,
                'session_id' => $session->id,
                'registration_id' => $flag->registration_id,
            ]);
        }
    }

    /** (Dé)flag libre jusqu'au début ; bloqué après startAt et sur séance annulée (§4.14.3). */
    private function guardWindow(Session $session): void
    {
        if ($session->hasStarted()) {
            throw new RuntimeException('Apéro figé : la séance a commencé.');
        }
        if ($session->isCancelled()) {
            throw new RuntimeException('Séance annulée.');
        }
    }

    /** Motif libre optionnel, borné à 140 caractères (§4.14.2). Vide → null. */
    private function normalizeMotif(?string $motif): ?string
    {
        $motif = trim((string) $motif);

        return $motif === '' ? null : mb_substr($motif, 0, 140);
    }
}
