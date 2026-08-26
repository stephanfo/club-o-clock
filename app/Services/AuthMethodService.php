<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Support\Collection;

// Disponibilité des moyens de connexion (PRD §4.1.1, interrupteurs §4.17). Source unique consommée
// par les contrôleurs d'auth, l'écran de connexion, le profil et l'écran de paramètres — la règle
// « ce moyen est-il ouvert ? » ne doit pas être réécrite à chaque surface.
//
// Le login par mot de passe n'a volontairement PAS d'interrupteur : c'est la voie garantie, ce qui
// borne le risque de verrouillage et laisse `club:create-admin` comme échappatoire.
class AuthMethodService
{
    /** Moyens pilotables par le club, mappés vers leur colonne de réglage. */
    public const SWITCHABLE = [
        'magic_link' => 'auth_magic_link_enabled',
        'google' => 'auth_google_enabled',
    ];

    /**
     * Le lien magique est-il ouvert ? L'interrupteur du club décide, SAUF en mode démo où il est
     * fermé d'office : le mailer y est forcé sur `log` (DemoMode::enforce), donc le lien promis
     * « dans ta boîte mail » n'arrive jamais. C'était l'onglet coché par défaut — l'impasse était
     * le premier geste du visiteur. Le mot de passe n'ayant pas d'interrupteur, il reste toujours
     * au moins un moyen d'entrer.
     */
    public function magicLinkEnabled(): bool
    {
        return DemoMode::magicLinkUsable() && ClubSettings::current()->auth_magic_link_enabled;
    }

    /**
     * Google exige DEUX conditions : l'interrupteur admin ET un client OAuth réellement configuré.
     * Sans client_id, le bouton « Continuer avec Google » menait à une erreur Google — une instance
     * qui n'a pas fait la démarche Cloud Console ne l'affiche donc plus du tout.
     */
    public function googleEnabled(): bool
    {
        return ClubSettings::current()->auth_google_enabled
            && filled(config('services.google.client_id'));
    }

    /** L'interrupteur est ouvert mais le client OAuth manque — l'admin doit le savoir (§4.17). */
    public function googleMisconfigured(): bool
    {
        return ClubSettings::current()->auth_google_enabled
            && blank(config('services.google.client_id'));
    }

    /**
     * Restera-t-il à cet utilisateur un moyen de se connecter s'il retire celui qu'il vise ?
     * (§4.1.2 « au moins une méthode active »). Pendant individuel de lockedOutBy(), qui pose la
     * même question à l'échelle du club — la règle ne doit exister qu'ici.
     *
     * Chaque voie ne compte que si le club l'a laissée ouverte (§4.17) : un email ne sauve rien si
     * le lien magique est coupé, une identité Google ne sauve rien si Google est coupé. Le mot de
     * passe, lui, est toujours utilisable — son login n'a pas d'interrupteur.
     *
     * @param  'password'|'oauth'  $removing  moyen que l'utilisateur cherche à retirer
     * @param  int|null  $identityId  identité OAuth visée, ignorée hors du cas 'oauth'
     */
    public function keepsAnotherWayIn(User $user, string $removing, ?int $identityId = null): bool
    {
        $hasPassword = $removing === 'password' ? false : $user->password !== null;

        $otherIdentities = $user->authIdentities()
            ->when($removing === 'oauth', fn ($q) => $q->where('id', '!=', $identityId))
            ->exists();

        return $hasPassword
            || ($user->email !== null && $this->magicLinkEnabled())
            || ($this->googleEnabled() && $otherIdentities);
    }

    /**
     * Comptes actifs qui n'auraient PLUS AUCUN moyen de se connecter si les interrupteurs valaient
     * l'état passé. Sert de garde avant toute coupure (§4.1.2 « au moins une méthode active »).
     *
     * L'enjeu est réel : les comptes créés par invitation ou activation de tutelle (§4.2.1) sont
     * passwordless, sans identité OAuth — le lien magique est leur SEUL accès, et le couper les
     * verrouillerait dehors définitivement.
     *
     * Un mot de passe suffit toujours à sauver un compte (le login MDP n'est jamais coupé).
     *
     * Les mineurs en P1 sont HORS de ce décompte : le PRD §4.2 les définit comme existant « sans
     * credential d'auth, email nul » — le parent agit pour eux depuis « mes enfants ». Ils n'ont
     * aucun accès à perdre, donc aucune coupure ne peut les verrouiller dehors. Les compter
     * interdisait à tout club ayant des P1 de couper quoi que ce soit, avec un refus nommant des
     * enfants qui ne se connectent pas. Même raison que l'exclusion des comptes inactifs et
     * anonymisés : on ne protège que des accès qui existent.
     *
     * Le critère est `is_minor` + `email IS NULL`, PAS la présence d'un garant : un mineur orphelin
     * de tutelle (guardian_id nul, en attente de rattachement §4.2) n'a pas davantage de credential.
     * C'est l'absence d'email qui sépare P1 de P2 (§4.2.1 « la transition P1→P2 crée le credential
     * et renseigne l'email ») : un P2 a son propre email, se connecte seul, et reste protégé ici.
     *
     * @return Collection<int,User>
     */
    public function lockedOutBy(bool $magicLink, bool $google): Collection
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->whereNull('password')
            // P1 (§4.2) : mineur sans email = pas de credential, aucun accès à verrouiller.
            ->whereNot(fn ($q) => $q->where('is_minor', true)->whereNull('email'));

        // Chaque moyen encore ouvert RETIRE de la liste les comptes qui peuvent l'emprunter.
        if ($magicLink) {
            $query->whereNull('email');
        }

        if ($google) {
            $query->whereDoesntHave('authIdentities');
        }

        return $query->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * Comptes qui perdraient tout accès si l'on coupait ce moyen, les autres interrupteurs restant
     * dans leur état courant. Le calcul est fait sur l'état CIBLE, pas sur l'état actuel.
     *
     * @return Collection<int,User>
     */
    public function lockedOutByDisabling(string $method): Collection
    {
        $magicLink = $this->magicLinkEnabled();
        $google = $this->googleEnabled();

        return match ($method) {
            'magic_link' => $this->lockedOutBy(false, $google),
            'google' => $this->lockedOutBy($magicLink, false),
            default => collect(),
        };
    }
}
