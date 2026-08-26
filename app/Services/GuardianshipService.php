<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Cycle de vie des comptes mineurs (PRD §4.2). Deux transitions explicites, tracées en AuditLog,
// aucune bascule automatique liée à l'âge.
//   P1 → P2 : ouverture d'un compte autonome pour l'enfant (email + jeton d'invitation). Le lien de
//             tutelle est CONSERVÉ automatiquement. L'envoi email et la page d'activation = J8/§4.1.3.
//   P2 → P3 : rupture manuelle du lien de tutelle (par l'enfant en P2, le parent garant, ou l'admin).
class GuardianshipService
{
    public function __construct(
        private NotificationDispatcher $notifier,
        private InvitationService $invitations,
    ) {}

    /**
     * P1 → P2 (§4.2.1) : pose l'email de l'enfant (si fourni), crée un jeton d'invitation (TTL =
     * invitation_link_days) et trace. Renvoie le token EN CLAIR (seul son hash est stocké) et émet
     * guardianship_invitation au SEUL pupille (email). Le lien de tutelle reste inchangé.
     */
    public function invite(User $ward, User $actor, ?string $email = null): string
    {
        $token = DB::transaction(function () use ($ward, $actor, $email) {
            if (! $ward->is_minor || $ward->guardian_id === null) {
                throw new RuntimeException('L\'autonomisation ne concerne qu\'un mineur ayant un parent garant.');
            }

            if ($email !== null && trim($email) !== '') {
                $email = mb_strtolower(trim($email));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Adresse email invalide.');
                }
                if (User::where('email', $email)->whereKeyNot($ward->id)->exists()) {
                    throw new RuntimeException('Cet email est déjà utilisé par un autre compte.');
                }
                $ward->update(['email' => $email]);
            }
            if (! $ward->email) {
                throw new RuntimeException('Renseigne l\'email de l\'enfant avant d\'envoyer l\'invitation.');
            }

            // DANS la transaction, une fois l'email posé : si aucun canal ne porte l'invitation, on
            // annule tout — email, jeton et trace. L'autonomisation N'EST PAS l'ouverture d'un
            // compte plus l'envoi d'un mail en bonus : sans le lien, l'enfant a un compte dont il
            // ignore l'existence et qu'il ne peut pas activer. Même raisonnement qu'InvitationService.
            //
            // La garde reste, mais ne se déclenche plus sur l'interrupteur club ni sur la pause :
            // l'invitation porte un accès au compte (cf. NotificationType::transactional()).
            if ($this->notifier->deliverableChannels(NotificationType::GuardianshipInvitation, $ward) === []) {
                throw new RuntimeException(
                    'Cet enfant ne peut pas recevoir l\'invitation : aucun canal ne peut la porter.'
                );
            }

            // Même jeton et même page d'activation que l'invitation d'adhérent (§4.1.3) : la frappe
            // vit dans InvitationService, elle n'a pas à exister en deux exemplaires.
            $token = $this->invitations->mint($ward);

            AuditLogger::record('guardianship_invite_sent', $actor, [
                'target_type' => User::class,
                'target_id' => $ward->id,
            ]);
            ActivityLogger::record('guardianship_invite_sent', $actor, ['user_id' => $ward->id]);

            return $token;
        });

        // Lien d'activation au pupille uniquement (dispatchTo : pas de routage vers le garant). Le
        // token clair voyage dans le payload jusqu'à l'envoi (ligne email seule, drainée puis purgée).
        $this->notifier->dispatchTo(NotificationType::GuardianshipInvitation, $ward, [
            'ward_id' => $ward->id,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * P2 → P3 (§4.2.2) : rompt le lien de tutelle. Effet immédiat — lien supprimé, AuditLog +
     * notif guardianship_severed unique aux deux destinataires. Idempotent si déjà rompu.
     *
     * Refus sur un pupille MINEUR en P1 : la transition part de P2 (§4.2 — le pupille a déjà un
     * compte propre). Rompre un P1 laisserait un User sans garant ET sans moyen de connexion —
     * plus personne ne pourrait agir dessus, ni l'enfant (aucun credential), ni le parent (détaché).
     * Même raisonnement que canSever pour l'enfant lui-même, et que MemberService::requestDeletion
     * (« ce compte est garant d'un mineur sans compte propre »). Le geste attendu est
     * l'autonomisation (invite, P1 → P2), puis la rupture.
     *
     * La garde est bornée aux MINEURS à dessein : un pupille devenu majeur en gardant son garant
     * (MemberService::updateDob) n'a plus accès à invite(), qui exige un mineur. L'étendre à lui le
     * rendrait définitivement captif — soit exactement le défaut qu'on corrige. Pour lui, la rupture
     * EST la sortie prévue (cf. le bandeau « Ce pupille est majeur » sur la fiche adhérent).
     */
    public function sever(User $ward, User $actor): void
    {
        $guardian = null;
        $severed = false;

        DB::transaction(function () use ($ward, $actor, &$guardian, &$severed) {
            if ($ward->guardian_id === null) {
                return;
            }

            if ($ward->email === null && $ward->is_minor) {
                throw new RuntimeException(
                    'Ce pupille n\'a pas de compte propre (P1) : ouvre-lui d\'abord un compte autonome, '
                    .'sinon il resterait sans garant et sans accès.'
                );
            }

            // Capture du garant AVANT de couper : après l'update, la relation est vide et le fan-out
            // ne l'atteindrait plus.
            $guardian = $ward->loadMissing('guardian')->guardian;

            $ward->update(['guardian_id' => null, 'guardianship_linked_at' => null]);

            AuditLogger::record('guardianship_severed', $actor, [
                'target_type' => User::class,
                'target_id' => $ward->id,
            ]);
            ActivityLogger::record('guardianship_severed', $actor, ['user_id' => $ward->id]);

            $severed = true;
        });

        if (! $severed) {
            return;
        }

        // Notif unique aux DEUX parties (§4.2.2), chacune adressée explicitement (le lien est rompu).
        $this->notifier->dispatchTo(NotificationType::GuardianshipSevered, $ward, ['ward_id' => $ward->id]);
        if ($guardian !== null) {
            $this->notifier->dispatchTo(NotificationType::GuardianshipSevered, $guardian, ['ward_id' => $ward->id]);
        }
    }

    /**
     * Rattache un mineur SANS garant à un adulte actif (geste admin). Complète le cycle §4.2 pour
     * les mineurs autonomes et les orphelins de tutelle (ex. garant supprimé) — le PRD ne pose le
     * lien qu'à la création / import CSV, cette action de rattrapage est une extension assumée.
     * La phase résultante se déduit comme partout de (guardian_id, email) : P1 si l'enfant n'a pas
     * de compte propre, P2 sinon.
     */
    public function link(User $ward, User $guardian, User $actor): void
    {
        DB::transaction(function () use ($ward, $guardian, $actor) {
            if (! $ward->is_minor || $ward->anonymized_at !== null) {
                throw new RuntimeException('Seul un mineur peut être rattaché à un garant.');
            }
            if ($ward->guardian_id !== null) {
                throw new RuntimeException('Ce mineur a déjà un garant — romps d\'abord la tutelle existante.');
            }
            if ($guardian->is_minor || ! $guardian->is_active || $guardian->anonymized_at !== null || $guardian->id === $ward->id) {
                throw new RuntimeException('Le garant doit être un adulte actif du club.');
            }

            $ward->update([
                'guardian_id' => $guardian->id,
                'guardianship_linked_at' => Carbon::now(),
            ]);

            AuditLogger::record('guardianship_linked', $actor, [
                'target_type' => User::class,
                'target_id' => $ward->id,
                'guardian_id' => $guardian->id,
            ]);
            ActivityLogger::record('guardianship_linked', $actor, ['user_id' => $ward->id]);
        });
    }

    /**
     * Qui peut rompre le lien (§4.2.2) : l'admin, le parent garant, ou l'enfant lui-même en P2
     * (interdit en P1, où l'enfant n'a pas de compte → il serait captif).
     */
    public static function canSever(User $actor, User $ward): bool
    {
        if ($ward->guardian_id === null) {
            return false;
        }
        if ($actor->hasRole('admin') || $actor->id === $ward->guardian_id) {
            return true;
        }

        // Enfant lui-même, uniquement en P2 (il a son propre compte/email).
        return $actor->id === $ward->id && $ward->email !== null;
    }
}
