<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InvitationToken;
use App\Models\MagicLinkToken;
use App\Models\NotificationOutbox;
use App\Models\Qualification;
use App\Models\User;
use App\Support\AgeCategory;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Mutations « adhérent » côté bureau (PRD §4.17.1, §4.1.3, §4.11.3 — J6.2).
// Centralise création + édition fiche : chaque geste persiste à l'unité (action immédiate),
// sous transaction, avec traçabilité. Sensibilité : identité/accès/rôle → AuditLog (survit à
// l'anonymisation via le snapshot actor_role) ; faits métier → ActivityLog.
class MemberService
{
    /**
     * Crée un adhérent (PRD §4.17.1). Dérive la catégorie principale + is_minor de la date de
     * naissance, attache surclassements / rôles / qualifs / garant, puis trace la création.
     *
     * @param  array{first_name:string,last_name:string,dob:string,email:?string,roles:array<int,string>,surclassements:array<int,int>,qualifications:array<int,int>,guardian_id:?int}  $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $dob = Carbon::parse($data['dob']);
            $isMinor = AgeCategory::isMinor($dob);

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'dob' => $dob->toDateString(),
                'roles' => array_values($data['roles'] ?? ['athlete']),
                'is_active' => true,
                'is_minor' => $isMinor,
                'guardian_id' => $isMinor ? ($data['guardian_id'] ?? null) : null,
                'guardianship_linked_at' => ($isMinor && ! empty($data['guardian_id'])) ? Carbon::now() : null,
            ]);

            // L'admin est de confiance : l'email qu'il saisit vient du dossier de licence, il n'a pas
            // à être reconfirmé par un aller-retour. Sans ça, le compte naissait MUET — le lien
            // magique exige un email vérifié (§4.1.1) et l'OAuth aussi, donc l'adhérent n'avait
            // aucun moyen d'entrer. Risque assumé (§4.1.3) : une adresse mal saisie donne la prise
            // du compte à un tiers ; en contrepartie l'invitation part à cette adresse et la
            // création est tracée en AuditLog. forceFill : email_verified_at n'est pas fillable,
            // c'est un fait de sécurité qu'on ne pose jamais depuis un tableau de formulaire.
            if (($data['email'] ?? null) !== null) {
                $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            }

            // Catégorie principale dérivée (peut être null : compte sans catégorie, cas limite §4.5).
            $primary = AgeCategory::derive($dob);
            $attach = [];
            if ($primary !== null) {
                $attach[$primary->id] = ['is_primary' => true];
            }
            foreach ($data['surclassements'] ?? [] as $categoryId) {
                if ($categoryId !== ($primary?->id)) {
                    $attach[$categoryId] = ['is_primary' => false];
                }
            }
            $user->categories()->sync($attach);

            foreach ($data['qualifications'] ?? [] as $qualificationId) {
                $user->qualifications()->attach($qualificationId, [
                    'attributed_at' => Carbon::now(),
                    'attributed_by' => $actor->id,
                ]);
            }

            // Création = acte sensible (ouvre une identité/accès) → AuditLog + ActivityLog.
            AuditLogger::record('member_created', $actor, [
                'target_type' => User::class,
                'target_id' => $user->id,
            ]);
            ActivityLogger::record('create_member', $actor, ['user_id' => $user->id]);

            return $user;
        });
    }

    /**
     * Mise à jour d'identité lors d'un import CSV (J6.5) : nom, prénom, date de naissance. La date
     * recalcule is_minor et la catégorie principale dérivée, en PRÉSERVANT les surclassements
     * manuels existants. Ne touche ni email, ni rôles, ni qualifs, ni lien de tutelle (décision
     * produit : l'import ne fait que rafraîchir l'état civil des fiches déjà connues par email).
     *
     * @param  array{first_name:string,last_name:string,dob:string,email?:?string}  $data
     */
    public function importUpdate(User $member, array $data, User $actor): void
    {
        DB::transaction(function () use ($member, $data, $actor) {
            $dob = Carbon::parse($data['dob']);

            $member->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'dob' => $dob->toDateString(),
                'is_minor' => AgeCategory::isMinor($dob),
            ]);

            // Recalcul de la principale ; les surclassements manuels (is_primary=false) sont conservés.
            $primary = AgeCategory::derive($dob);
            $surclassements = $member->categories()->wherePivot('is_primary', false)->pluck('categories.id');

            $attach = [];
            if ($primary !== null) {
                $attach[$primary->id] = ['is_primary' => true];
            }
            foreach ($surclassements as $categoryId) {
                if ($categoryId !== ($primary?->id)) {
                    $attach[$categoryId] = ['is_primary' => false];
                }
            }
            $member->categories()->sync($attach);

            // Modification d'identité (nom/DOB) = acte sensible, comme le changement d'email → AuditLog
            // (survit à l'anonymisation via le snapshot actor_role) + ActivityLog métier.
            AuditLogger::record('member_updated', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);
            ActivityLogger::record('member_imported_update', $actor, ['user_id' => $member->id]);
        });
    }

    /**
     * Correction de la date de naissance depuis la fiche (§4.1.3, action immédiate). Recalcule
     * is_minor + la catégorie principale dérivée, en PRÉSERVANT les surclassements manuels — même
     * logique que l'import (importUpdate). Ne touche pas au lien de tutelle : un mineur devenu majeur
     * garde son garant jusqu'à une rupture explicite (P2→P3, GuardianshipService), comme à l'import.
     * Acte d'identité sensible → AuditLog (survit à l'anonymisation) + ActivityLog métier.
     */
    public function updateDob(User $member, string $dob, User $actor): void
    {
        DB::transaction(function () use ($member, $dob, $actor) {
            $parsed = Carbon::parse($dob);

            $member->update([
                'dob' => $parsed->toDateString(),
                'is_minor' => AgeCategory::isMinor($parsed),
            ]);

            // Recalcul de la principale ; les surclassements manuels (is_primary=false) sont conservés.
            $primary = AgeCategory::derive($parsed);
            $surclassements = $member->categories()->wherePivot('is_primary', false)->pluck('categories.id');

            $attach = [];
            if ($primary !== null) {
                $attach[$primary->id] = ['is_primary' => true];
            }
            foreach ($surclassements as $categoryId) {
                if ($categoryId !== ($primary?->id)) {
                    $attach[$categoryId] = ['is_primary' => false];
                }
            }
            $member->categories()->sync($attach);

            AuditLogger::record('member_updated', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
                'motif' => 'dob_changed',
            ]);
            ActivityLogger::record('member_dob_changed', $actor, ['user_id' => $member->id]);
        });
    }

    /** Active/désactive un rôle cumulable (§5.1). Trace role_changed (Audit) + métier (Activity). */
    public function toggleRole(User $member, string $role, User $actor): bool
    {
        if (! in_array($role, User::ROLES, true)) {
            throw new RuntimeException("Rôle inconnu : {$role}.");
        }

        return DB::transaction(function () use ($member, $role, $actor) {
            $roles = $member->roles ?? [];
            $granted = ! in_array($role, $roles, true);

            $roles = $granted
                ? [...$roles, $role]
                : array_values(array_filter($roles, fn ($r) => $r !== $role));

            $member->update(['roles' => array_values($roles)]);

            AuditLogger::record('role_changed', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
                'motif' => ($granted ? 'grant_' : 'revoke_').$role,
            ]);
            ActivityLogger::record($granted ? 'role_granted' : 'role_revoked', $actor, [
                'user_id' => $member->id,
            ]);

            return $granted;
        });
    }

    /** Ajoute un surclassement manuel (§4.5). No-op si déjà rattaché ou si c'est la principale. */
    public function addSurclassement(User $member, Category $category, User $actor): void
    {
        DB::transaction(function () use ($member, $category, $actor) {
            if ($member->categories()->whereKey($category->id)->exists()) {
                return;
            }
            $member->categories()->attach($category->id, ['is_primary' => false]);
            ActivityLogger::record('category_added', $actor, ['user_id' => $member->id]);
        });
    }

    /** Retire un surclassement manuel (§4.5). La catégorie principale n'est jamais retirable ici. */
    public function removeSurclassement(User $member, Category $category, User $actor): void
    {
        DB::transaction(function () use ($member, $category, $actor) {
            $pivot = $member->categories()->whereKey($category->id)->first()?->pivot;
            if ($pivot === null || $pivot->is_primary) {
                return;
            }
            $member->categories()->detach($category->id);
            ActivityLogger::record('category_removed', $actor, ['user_id' => $member->id]);
        });
    }

    /** Attribue une qualification (§4.11.3). Pivot attributed_at/by ; no-op si déjà présente. */
    public function addQualification(User $member, Qualification $qualification, User $actor, ?string $expiresAt = null): void
    {
        DB::transaction(function () use ($member, $qualification, $actor, $expiresAt) {
            if ($member->qualifications()->whereKey($qualification->id)->exists()) {
                return;
            }
            $member->qualifications()->attach($qualification->id, [
                'expires_at' => $expiresAt ?: null,
                'attributed_at' => Carbon::now(),
                'attributed_by' => $actor->id,
            ]);
            ActivityLogger::record('qualification_assigned', $actor, ['user_id' => $member->id]);
        });
    }

    /**
     * Pose / modifie / efface la date d'expiration d'une qualification déjà attribuée (§4.11.3 —
     * alimente le badge « expirée » agrégé sur les fiches séance). Null = pas d'expiration.
     */
    public function setQualificationExpiry(User $member, Qualification $qualification, ?string $expiresAt, User $actor): void
    {
        DB::transaction(function () use ($member, $qualification, $expiresAt, $actor) {
            if (! $member->qualifications()->whereKey($qualification->id)->exists()) {
                return;
            }
            $member->qualifications()->updateExistingPivot($qualification->id, [
                'expires_at' => $expiresAt ?: null,
            ]);
            ActivityLogger::record('qualification_updated', $actor, ['user_id' => $member->id]);
        });
    }

    /** Retire une qualification (§4.11.3). */
    public function removeQualification(User $member, Qualification $qualification, User $actor): void
    {
        DB::transaction(function () use ($member, $qualification, $actor) {
            if (! $member->qualifications()->whereKey($qualification->id)->exists()) {
                return;
            }
            $member->qualifications()->detach($qualification->id);
            ActivityLogger::record('qualification_revoked', $actor, ['user_id' => $member->id]);
        });
    }

    /**
     * Change l'email de connexion (§4.1.3). Acte sensible (accès) → AuditLog + ActivityLog.
     *
     * Changer l'adresse RÉVOQUE tout ce qui pointait l'ancienne. C'est le cœur du geste, pas un
     * ménage : on corrige typiquement une coquille de saisie, donc les secrets déjà partis sont
     * précisément entre les mains de quelqu'un d'autre. Quatre familles à couper —
     *   - les invitations d'activation non consommées, indexées par user_id : elles survivraient
     *     au changement d'adresse et resteraient honorées jusqu'à 30 jours ;
     *   - les liens magiques et codes émis pour l'ancienne adresse ;
     *   - les jetons de réinitialisation de mot de passe de l'ancienne adresse ;
     *   - les SESSIONS déjà ouvertes et les cookies « se souvenir de moi ». Sans elles, révoquer
     *     les jetons ne sert à rien : le tiers qui a activé le compte depuis l'ancienne adresse
     *     reste connecté indéfiniment, exactement le risque que le geste prétend couvrir. Seule
     *     exception, la session de l'acteur quand il corrige SA propre adresse (cf. revokeSessions) :
     *     il vient de prouver qu'il la contrôle, et le geste n'a pas à le déconnecter lui-même.
     *
     * La nouvelle adresse est marquée vérifiée pour la même raison qu'à la création : l'admin la
     * tient du dossier de licence, et sans email vérifié le compte redeviendrait muet (§4.1.1).
     */
    public function updateEmail(User $member, ?string $email, User $actor): void
    {
        DB::transaction(function () use ($member, $email, $actor) {
            $ancien = $member->email;
            $nouveau = $email ?: null;

            if ($ancien === $nouveau) {
                return;
            }

            $member->update(['email' => $nouveau]);

            // email_verified_at n'est pas fillable : fait de sécurité, jamais posé depuis un tableau
            // de formulaire. Vidé si l'adresse disparaît — il ne vérifie plus rien.
            $member->forceFill(['email_verified_at' => $nouveau !== null ? Carbon::now() : null])->save();

            InvitationToken::where('user_id', $member->id)->whereNull('consumed_at')->delete();

            if ($ancien !== null) {
                MagicLinkToken::where('email', $ancien)->delete();
                DB::table('password_reset_tokens')->where('email', $ancien)->delete();
            }

            $this->revokeSessions($member, $actor);

            AuditLogger::record('email_changed', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);
            ActivityLogger::record('member_email_changed', $actor, ['user_id' => $member->id]);
        });
    }

    /**
     * Coupe les accès déjà ouverts d'un adhérent : lignes de session ET cookies « se souvenir de
     * moi ». Supprimer les sessions ne suffit pas — les deux chemins de login (lien magique, OAuth)
     * posent un cookie remember, et Laravel ré-authentifierait l'appareil au passage suivant.
     * Régénérer `remember_token` invalide tous ces cookies d'un coup (le jeton est par-utilisateur,
     * pas par-session), d'où la ré-émission de celui de l'acteur quand il est sa propre cible.
     *
     * `$actor` non nul ÉPARGNE la session courante quand l'acteur est la cible. Un admin peut
     * corriger sa propre adresse depuis sa fiche : tout couper le déconnectait lui-même, et l'écran
     * affichait alors un succès vert sur une session déjà morte — il croyait pouvoir enchaîner et
     * se faisait éjecter vers /login au geste suivant, sans rien pour relier les deux. Sa session
     * est légitime par construction : il vient de prouver qu'il la contrôle en agissant. Ce que la
     * révocation vise, ce sont les TIERS qui détenaient l'ancienne adresse. Même raisonnement que
     * Profil::purgeOtherSessions(), qui préserve la courante pour la même raison.
     *
     * `$actor` nul = ne rien épargner (suppression RGPD : le compte doit perdre tous ses accès).
     */
    private function revokeSessions(User $member, ?User $actor = null): void
    {
        $sessionCourante = $actor !== null && $actor->id === $member->id && auth()->id() === $member->id
            ? session()->getId()
            : null;

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $member->id)
            ->when($sessionCourante !== null, fn ($q) => $q->where('id', '!=', $sessionCourante))
            ->delete();

        $member->forceFill(['remember_token' => null])->save();

        // Ré-émission APRÈS la purge : Auth::login régénère l'id de session courante, donc le faire
        // avant ferait porter le `!=` ci-dessus sur un id périmé — la session épargnée serait celle
        // qui n'existe plus, et l'acteur se retrouverait déconnecté malgré la garde.
        if ($sessionCourante !== null) {
            Auth::login($member, remember: true);
        }
    }

    // --- Suppression de compte RGPD (PRD §4.3, §4.18.3) ---

    /**
     * Voie 2 (initiative admin, §4.3) : ouvre le tampon bloquant. Pose deletion_requested_at +
     * is_active=false (l'athlète ne peut plus se connecter). Données et lien de tutelle conservés
     * pendant le tampon. Idempotent : no-op si une demande est déjà en cours ou compte anonymisé.
     */
    public function requestDeletion(User $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor) {
            if ($member->deletion_requested_at !== null || $member->anonymized_at !== null) {
                return;
            }

            // Garde gouvernance : ne jamais désactiver le dernier admin actif — sinon plus personne
            // pour gouverner ni confirmer la suppression définitive à J+7. Couvre voie 1 (auto-demande)
            // et voie 2 (initiative admin).
            if ($this->isLastActiveAdmin($member)) {
                throw new RuntimeException('Impossible : ce compte est le dernier administrateur actif du club.');
            }

            // Garde tutelle (§4.2) : un garant avec pupille P1 (sans compte propre) ne peut pas
            // partir — l'enfant deviendrait ingérable et injoignable. Autonomiser (P1→P2) ou
            // reparenter l'enfant d'abord. Les pupilles P2 ne bloquent pas (tutelle rompue à
            // l'anonymisation, l'enfant reste autonome).
            $p1Wards = $member->wards()->whereNull('email')->whereNull('anonymized_at')->count();
            if ($p1Wards > 0) {
                throw new RuntimeException(
                    'Impossible : ce compte est garant d\'un mineur sans compte propre (P1). '
                    .'Autonomise l\'enfant ou rattache-le à un autre garant avant la suppression.'
                );
            }

            // deletion_requested_at est non-fillable (posé par ce flow serveur uniquement) → forceFill.
            $member->forceFill([
                'deletion_requested_at' => Carbon::now(),
                'is_active' => false,
            ])->save();

            AuditLogger::record('account_deletion_requested', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);
        });
    }

    /**
     * `$member` est-il le dernier administrateur actif du club ? (rôle admin + aucun autre user admin
     * actif et non anonymisé). Les admins en cours de suppression ont déjà `is_active=false`, donc exclus.
     */
    public function isLastActiveAdmin(User $member): bool
    {
        if (! in_array('admin', $member->roles ?? [], true)) {
            return false;
        }

        return ! User::query()
            ->where('id', '!=', $member->id)
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->whereJsonContains('roles', 'admin')
            ->exists();
    }

    /** Annulation pendant le tampon (§4.3) : réactive le compte. No-op si aucune demande en cours. */
    public function cancelDeletion(User $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor) {
            if ($member->deletion_requested_at === null || $member->anonymized_at !== null) {
                return;
            }

            // deletion_requested_at non-fillable → forceFill pour le remettre à null.
            $member->forceFill([
                'deletion_requested_at' => null,
                'is_active' => true,
            ])->save();

            AuditLogger::record('account_deletion_cancelled', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);
        });
    }

    /**
     * Suppression définitive (§4.3, §4.18.3). Garde serveur redondante avec l'UI : refuse tant que
     * le tampon de 7 j n'est pas écoulé. Anonymisation « tombstone » : la ligne User est conservée
     * (id stable → corrélation des journaux préservée sans réécriture), mais vidée de toute donnée
     * personnelle ; les credentials (OAuth, invitations, magic links, préférences) sont supprimés.
     * Trace account_deleted avec actor = admin qui confirme (jamais system).
     *
     * @throws RuntimeException si le tampon n'est pas écoulé.
     */
    public function confirmDeletion(User $member, User $actor): void
    {
        if (! $member->isDeletionEligible()) {
            throw new RuntimeException('Tampon RGPD de 7 jours non écoulé : suppression définitive impossible.');
        }

        // Rupture des tutelles restantes AVANT le scrub (§4.2.2) : sinon les pupilles pointeraient
        // un tombstone (notifs du P2 routées vers un garant sans email, fiche « Compte supprimé »).
        // GuardianshipService trace (AuditLog guardianship_severed) et notifie chaque partie.
        $guardianship = app(GuardianshipService::class);
        foreach ($member->wards()->whereNull('anonymized_at')->get() as $ward) {
            $guardianship->sever($ward, $actor);
        }

        DB::transaction(function () use ($member, $actor) {
            $email = $member->email;

            // Révocation des accès (credentials). Le tombstone reste, ces lignes partent.
            $member->authIdentities()->delete();
            InvitationToken::where('user_id', $member->id)->delete();
            $member->notificationPreferences()->delete();

            // Les SESSIONS déjà ouvertes, sans quoi l'effacement ne ferme rien : les gardes
            // `is_active` / `anonymized_at` ne valent que pour un NOUVEAU login (§4.3), donc un
            // appareil resté connecté continuerait de naviguer dans l'application sur un compte
            // effacé. Purger `remember_token` fait partie du scrub juste en dessous.
            $this->revokeSessions($member);

            // Minimisation + non-contact après effacement (§4.3) : les endpoints push identifient
            // les appareils de la personne (le cascadeOnDelete ne joue jamais, le tombstone reste),
            // et une ligne outbox encore pending enverrait une notification APRÈS la suppression.
            $member->pushSubscriptions()->delete();
            NotificationOutbox::where('user_id', $member->id)->where('status', 'pending')->delete();
            if ($email !== null) {
                MagicLinkToken::where('email', $email)->delete();
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            // Scrub des données personnelles. Ligne conservée comme tombstone (anonymized_at).
            $member->forceFill([
                'first_name' => 'Compte',
                'last_name' => 'supprimé',
                'email' => null,
                'email_verified_at' => null,
                'password' => null,
                'remember_token' => null,
                'dob' => null,
                'is_minor' => false,
                'guardian_id' => null,
                'guardianship_linked_at' => null,
                'is_active' => false,
                'athlete_access_suspended' => false,
                'anonymized_at' => Carbon::now(),
            ])->save();

            // Acte RGPD majeur : actor = admin qui confirme, JAMAIS system (§4.3). Le snapshot
            // actor_role survit ; les FK des journaux pointant ce user_id sont désormais anonymes.
            AuditLogger::record('account_deleted', $actor, [
                'target_type' => User::class,
                'target_id' => $member->id,
            ]);
        });
    }
}
