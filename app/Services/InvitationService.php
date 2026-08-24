<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// Invitations d'activation de compte (PRD §4.1.3). Deux origines partagent le même jeton et la même
// page d'activation : l'adhérent créé par le bureau, et le mineur autonomisé (§4.2.1, via
// GuardianshipService qui appelle mint() ici).
//
// Il n'y a pas d'inscription publique : l'invitation est LA porte d'entrée d'un adhérent dans
// l'instance. Sans elle, un compte créé par l'admin reste muet — personne ne sait qu'il existe.
class InvitationService
{
    public function __construct(
        private NotificationDispatcher $notifier,
        private OutboxDrainer $drainer,
    ) {}

    /**
     * Frappe un jeton d'activation et renvoie le token EN CLAIR (seul son hash est stocké).
     *
     * Une seule invitation active à la fois : les précédentes non consommées sont supprimées, sinon
     * un lien qu'on croyait remplacé resterait honoré. TTL = ClubSettings.invitation_link_days.
     */
    public function mint(User $user): string
    {
        $token = Str::random(64);

        InvitationToken::where('user_id', $user->id)->whereNull('consumed_at')->delete();

        InvitationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(ClubSettings::current()->invitation_link_days),
        ]);

        return $token;
    }

    /**
     * Invite un adhérent à activer son compte.
     *
     * `$immediate` distingue les deux cadences d'envoi. Un geste unitaire draine tout de suite :
     * l'admin veut voir « invitation partie » avant de quitter l'écran. Un import CSV, non — 200
     * envois SMTP synchrones dans une requête, c'est 40 à 100 s et un timeout garanti sur mutualisé.
     * L'outbox EST déjà la file d'attente (backoff, tentatives, rejeu depuis Admin → Envois), et le
     * cron la draine par lots toutes les 5 min : aucune infrastructure à ajouter, ce que le cadrage
     * exclut de toute façon (pas de worker long-running sur mutualisé).
     *
     * @return Collection<int,NotificationOutbox> lignes créées
     */
    public function sendToMember(User $user, User $actor, bool $immediate = true): Collection
    {
        if ($user->email === null) {
            throw new RuntimeException('Renseigne l\'email de l\'adhérent avant d\'envoyer l\'invitation.');
        }

        if ($user->anonymized_at !== null) {
            throw new RuntimeException('Ce compte a été anonymisé.');
        }

        // is_active couvre aussi la demande de suppression en cours (§4.3) : on n'invite pas
        // quelqu'un à activer un compte dont l'effacement est engagé.
        if (! $user->is_active) {
            throw new RuntimeException('Ce compte n\'est pas actif.');
        }

        // AVANT de frapper le jeton : rien ne sert d'ouvrir une autorisation de 30 jours si aucun
        // canal ne porte l'invitation. C'est la différence entre un effet de bord manqué et un
        // geste sans objet — sans cette garde, l'admin lisait « invitation envoyée », le jeton
        // vivant excluait l'adhérent du rattrapage pendant 30 jours, et rien n'était parti.
        if ($this->notifier->deliverableChannels(NotificationType::MemberInvitation, $user) === []) {
            throw new RuntimeException($this->motifNonDelivrable($user));
        }

        $token = DB::transaction(function () use ($user, $actor) {
            $token = $this->mint($user);

            AuditLogger::record('member_invite_sent', $actor, [
                'target_type' => User::class,
                'target_id' => $user->id,
            ]);
            ActivityLogger::record('member_invite_sent', $actor, ['user_id' => $user->id]);

            return $token;
        });

        // Hors transaction, comme GuardianshipService : on n'émet rien qui puisse être annulé, et on
        // garde la transaction courte. dispatchTo (pas dispatch) — adressage explicite, sans routage
        // vers un garant : c'est l'adhérent lui-même qu'on invite.
        $lines = $this->notifier->dispatchTo(NotificationType::MemberInvitation, $user, [
            'token' => $token,
        ]);

        // Filet : entre la garde ci-dessus et ici, un admin a pu couper le canal email. Le jeton
        // frappé pour rien repart — le laisser vivre bloquerait le rattrapage 30 jours durant.
        if ($lines->isEmpty()) {
            InvitationToken::where('user_id', $user->id)->whereNull('consumed_at')->delete();

            throw new RuntimeException($this->motifNonDelivrable($user));
        }

        if ($immediate) {
            $this->drainer->drainNow($lines);
        }

        return $lines;
    }

    /** Pourquoi l'invitation ne peut pas partir — message actionnable, pas un « échec d'envoi ». */
    private function motifNonDelivrable(User $user): string
    {
        if (! ClubSettings::current()->channelEnabled('email')) {
            return 'Le canal email du club est coupé (Admin → Réglages) : l\'invitation ne peut pas partir.';
        }

        if ($user->loadMissing('notificationPreferences')->notificationPreferences?->paused) {
            return 'Cet adhérent a mis ses notifications en pause : l\'invitation ne peut pas partir.';
        }

        return 'Cet adhérent a désactivé ce type de notification : l\'invitation ne peut pas partir.';
    }

    /**
     * Adhérents actifs qui n'ont jamais activé leur compte et n'ont aucune invitation en cours.
     *
     * Un compte est réputé activé s'il s'est déjà connecté (last_login_at, tous moyens confondus),
     * ou s'il porte un mot de passe, une identité OAuth ou un jeton consommé — d'où l'élagage
     * restreint d'InvitationToken (les jetons consommés sont conservés, ils sont le marqueur
     * d'activation). Sert l'action de masse qui rattrape un import CSV envoyé en silence.
     *
     * Rendue en REQUÊTE et non en Collection : l'écran n'en veut souvent que le compte, et l'action
     * de masse n'en prend que les premiers. Matérialiser toute la table pour ça se payait à chaque
     * frappe dans la recherche.
     *
     * @return Builder<User>
     */
    public function awaitingInvitationQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->whereNotNull('email')
            ->whereNull('password')
            ->whereNull('last_login_at')
            ->whereDoesntHave('authIdentities')
            ->whereDoesntHave('invitations', fn ($q) => $q->whereNotNull('consumed_at'))
            ->whereDoesntHave('invitations', fn ($q) => $q->whereNull('consumed_at')->where('expires_at', '>', Carbon::now()))
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /**
     * Matérialise awaitingInvitationQuery(), bornée EN BASE quand un plafond est donné.
     *
     * @return Collection<int,User>
     */
    public function awaitingInvitation(?int $limit = null): Collection
    {
        $query = $this->awaitingInvitationQuery();

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
