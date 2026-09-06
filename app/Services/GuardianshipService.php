<?php

namespace App\Services;

use App\Models\InvitationToken;
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
            // L'âge n'entre pas ici : la question est « a-t-il un accès à lui ? », pas « quel âge
            // a-t-il ? ». Un pupille devenu majeur en gardant son garant (MemberService::updateDob)
            // a d'autant plus droit à son compte — le lui refuser le laissait sans aucune sortie,
            // puisque la rupture, elle, lui retire son garant sans rien lui donner en échange.
            if ($ward->guardian_id === null) {
                throw new RuntimeException('L\'autonomisation ne concerne qu\'un pupille ayant un parent garant.');
            }

            // Mêmes gardes de joignabilité que InvitationService::sendToMember() — l'exemption de
            // l'interrupteur et de la pause porte sur le CONSENTEMENT, jamais sur la joignabilité
            // (§4.15.1). Sans elles, on ouvrait un compte autonome à un pupille anonymisé ou dont la
            // suppression est engagée : jeton de 30 jours frappé, audit écrit, mail jamais lisible.
            if ($ward->anonymized_at !== null) {
                throw new RuntimeException('Ce compte a été anonymisé.');
            }

            // is_active couvre aussi la demande de suppression en cours (§4.3).
            if (! $ward->is_active) {
                throw new RuntimeException('Ce compte n\'est pas actif.');
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
        $lines = $this->notifier->dispatchTo(NotificationType::GuardianshipInvitation, $ward, [
            'ward_id' => $ward->id,
            'token' => $token,
        ]);

        // Même filet qu'InvitationService::sendToMember() : entre la garde ci-dessus et ici, l'état
        // a pu bouger. Le jeton frappé pour rien repart — le laisser vivre bloquerait le rattrapage
        // 30 jours durant, avec un audit qui affirme un envoi qui n'a pas eu lieu.
        if ($lines->isEmpty()) {
            InvitationToken::where('user_id', $ward->id)->whereNull('consumed_at')->delete();

            throw new RuntimeException(
                'Cet enfant ne peut pas recevoir l\'invitation : aucun canal ne peut la porter.'
            );
        }

        return $token;
    }

    /**
     * P2 → P3 (§4.2.2) : rompt le lien de tutelle. Effet immédiat — lien supprimé, AuditLog +
     * notif guardianship_severed unique aux deux destinataires. Idempotent si déjà rompu.
     *
     * Refus sur un pupille EN P1, quel que soit son âge : la transition part de P2 (§4.2 — le
     * pupille a déjà un compte propre). Rompre un P1 laisserait un User sans garant ET sans moyen
     * de connexion — plus personne ne pourrait agir dessus, ni lui (aucun credential), ni le parent
     * (détaché) —, et le rattacher à nouveau est impossible passé la majorité. Même raisonnement
     * que canSever pour l'enfant lui-même, et que MemberService::requestDeletion (« ce compte est
     * garant d'un mineur sans compte propre »). Le geste attendu est l'autonomisation (invite,
     * P1 → P2), désormais ouverte à tout âge, puis la rupture.
     *
     * La garde valait autrefois pour les seuls mineurs, au motif qu'invite() exigeait un mineur et
     * que la rupture était donc l'unique sortie d'un pupille majeur. C'était une sortie en trompe
     * l'œil : elle produisait un compte sans garant ni accès, que plus rien ne pouvait reprendre.
     * invite() ne regardant plus l'âge, la sortie existe vraiment, et la garde peut protéger tout
     * le monde (carnet de retours terrain, 2026-09-04).
     */
    public function sever(User $ward, User $actor): void
    {
        $guardian = null;
        $severed = false;

        DB::transaction(function () use ($ward, $actor, &$guardian, &$severed) {
            if ($ward->guardian_id === null) {
                return;
            }

            if ($ward->email === null) {
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
            // Le sujet est posé à la main : dispatchTo() n'applique pas le routage parent/enfant,
            // donc il n'a pas de sujet à déduire. Sans lui, un garant de plusieurs enfants recevait
            // « Lien de tutelle rompu » sans savoir lequel.
            $this->notifier->dispatchTo(NotificationType::GuardianshipSevered, $guardian, [
                'ward_id' => $ward->id,
                'subject_id' => $ward->id,
                'subject_first_name' => $ward->first_name,
            ]);
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
            // Minorité LÉGALE, pas âge de saison : ce dernier déclarait majeur — jusqu'à douze mois
            // à l'avance — un adhérent qui ne l'était pas, et lui interdisait donc tout garant.
            // Sans date de naissance, la minorité n'est pas établie : on refuse plutôt que de
            // supposer (fiche incomplète, compte anonymisé).
            if (! $ward->isLegallyMinor() || $ward->anonymized_at !== null) {
                throw new RuntimeException('Seul un mineur peut être rattaché à un garant.');
            }
            if ($ward->guardian_id !== null) {
                throw new RuntimeException('Ce mineur a déjà un garant — utilise le changement de garant.');
            }
            if ($guardian->isLegallyMinor() || ! $guardian->is_active || $guardian->anonymized_at !== null || $guardian->id === $ward->id) {
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
     * Remplace le parent garant d'un pupille — rupture et rattachement dans UNE transaction.
     *
     * Le lien ne se posait qu'à la création (formulaire, import CSV) : le reprendre imposait de
     * rompre puis de rattacher, or la rupture est refusée à un P1 — à raison. Le garant d'un enfant
     * sans compte propre était donc définitif, et un divorce, un décès ou une simple erreur de
     * saisie n'avaient aucune issue dans l'outil (carnet de retours terrain, 2026-09-04).
     *
     * L'atomicité EST la garde : l'état « sans garant », que sever() protège, n'existe à aucun
     * instant observable, pas même en cas d'échec — la transaction rend alors le lien d'origine.
     * C'est pourquoi ce geste peut servir un P1 là où l'enchaînement de deux appels ne le pouvait
     * pas. Admin uniquement (§4.1.3) : la fiche adhérent en est le seul appelant.
     *
     * Le journal reçoit les DEUX actions habituelles plutôt qu'un troisième verbe : un audit qui
     * cherche `guardianship_severed` trouve toutes les ruptures, celui qui cherche
     * `guardianship_linked` tous les rattachements, et leur horodatage commun dit le remplacement.
     */
    public function relink(User $ward, User $newGuardian, User $actor): void
    {
        $formerGuardian = null;

        DB::transaction(function () use ($ward, $newGuardian, $actor, &$formerGuardian) {
            // Relecture DANS la transaction : le modèle vient d'une requête antérieure (composant
            // Livewire, second onglet), et les gardes qui suivent doivent juger l'état réel, pas
            // celui qu'on croyait au moment de l'affichage.
            $ward->refresh();

            if ($ward->guardian_id === null) {
                throw new RuntimeException('Ce pupille n\'a pas de garant : rattache-lui-en un.');
            }
            if ($ward->guardian_id === $newGuardian->id) {
                throw new RuntimeException('Ce garant est déjà celui de ce pupille.');
            }
            // Pas de condition d'âge ici, à la différence de link() — et ce n'est pas un oubli.
            // link() CRÉE une tutelle : lui ouvrir les majeurs reviendrait à trancher une question
            // produit (cf. carnet, points 4 et 5). relink() n'en crée aucune, il SUBSTITUE un garant
            // à un autre sur un lien qui existe déjà : il ne peut donc pas placer sous tutelle
            // quelqu'un qui n'y était pas. L'exiger rouvrait une impasse — un pupille arrivé à 18
            // ans sans compte propre ne peut ni être rompu (il resterait sans accès) ni être
            // autonomisé faute d'email connu, et son garant devenait alors indéplaçable ET
            // insupprimable, MemberService::requestDeletion le refusant tant qu'un P1 lui pend.
            if ($ward->anonymized_at !== null) {
                throw new RuntimeException('Ce compte a été anonymisé.');
            }
            if ($newGuardian->isLegallyMinor() || ! $newGuardian->is_active || $newGuardian->anonymized_at !== null || $newGuardian->id === $ward->id) {
                throw new RuntimeException('Le garant doit être un adulte actif du club.');
            }

            // Capture AVANT l'écriture : après, la relation pointe le nouveau garant et le fan-out
            // n'atteindrait plus celui qu'on détache.
            $formerGuardian = $ward->loadMissing('guardian')->guardian;

            $ward->update([
                'guardian_id' => $newGuardian->id,
                'guardianship_linked_at' => Carbon::now(),
            ]);

            AuditLogger::record('guardianship_severed', $actor, [
                'target_type' => User::class,
                'target_id' => $ward->id,
            ]);
            AuditLogger::record('guardianship_linked', $actor, [
                'target_type' => User::class,
                'target_id' => $ward->id,
                'guardian_id' => $newGuardian->id,
            ]);
            ActivityLogger::record('guardianship_severed', $actor, ['user_id' => $ward->id]);
            ActivityLogger::record('guardianship_linked', $actor, ['user_id' => $ward->id]);
        });

        // Seul le garant SORTANT est notifié, et par la notification qui dit exactement ce qui lui
        // arrive : son lien est rompu. Le pupille, lui, n'a rien perdu — lui envoyer « Lien de
        // tutelle rompu » serait faux ; et le garant entrant vient d'être désigné par l'admin, qui
        // le voit apparaître dans « Mes enfants ». Dire proprement « ton garant a changé » demande
        // un type de notification qui n'existe pas encore ; le jour où il existera, c'est ici.
        if ($formerGuardian !== null) {
            $this->notifier->dispatchTo(NotificationType::GuardianshipSevered, $formerGuardian, [
                'ward_id' => $ward->id,
                'subject_id' => $ward->id,
                'subject_first_name' => $ward->first_name,
            ]);
        }
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
