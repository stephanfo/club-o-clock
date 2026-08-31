<?php

namespace App\Notifications;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use Illuminate\Support\Carbon;

// Rend une ligne d'outbox en contenu présentable (titre, corps, lien profond), partagé par les
// canaux réels (push + email) — un seul endroit pour dériver l'affichage du couple type + payload.
//
// Rendu AUTOPORTEUR : le payload transporte tout ce qu'il faut dire (le sujet quand ce n'est pas le
// destinataire, la séance quand il y en a une), figé à l'ÉMISSION. Aucun chargement d'entité ici
// (décision J8.6, cadrage §7.14) : le drain ne fait aucune requête, et l'entité peut avoir disparu
// entre l'émission et l'envoi sans casser la notification.
//
// Corollaire : CHAQUE enrichissement est conditionné à la présence de sa clé, et retombe sur le
// libellé/description du type. Ce n'est pas une précaution de migration mais un invariant — une
// invitation n'a jamais de séance, une ligne `failed` se rejoue longtemps après, et une clé supposée
// présente jetterait au drain, où la notification n'arriverait jamais (cf. le bug EnrolledByCoach
// couvert par test_every_notification_type_renders_without_error).
class NotificationRenderer
{
    /**
     * @return array{title:string,body:string,url:string}
     */
    public function render(NotificationOutbox $line): array
    {
        $type = NotificationType::from($line->type);
        $payload = $line->payload ?? [];
        $subjectId = $this->subjectId($line);

        return [
            'title' => $this->titleFor($type, $payload, $subjectId),
            'body' => $this->bodyFor($type, $payload, $subjectId),
            'url' => $this->urlFor($type, $payload, $line, $subjectId),
        ];
    }

    /**
     * Sujet de la notification quand ce n'est PAS le destinataire (routage parent/enfant §4.15.5).
     * Null dès que les deux coïncident : un parent garant lui-même athlète doit voir au premier coup
     * d'œil laquelle de ses notifications le concerne, lui, et laquelle concerne son enfant.
     */
    private function subjectId(NotificationOutbox $line): ?int
    {
        $subjectId = $line->payload['subject_id'] ?? null;

        return ($subjectId !== null && $subjectId !== $line->user_id) ? (int) $subjectId : null;
    }

    /**
     * Titre = libellé du type, préfixé du prénom du sujet le cas échéant. Le prénom va au TITRE et
     * non au corps : l'écran de notifications de l'OS empile les titres et tronque les corps, donc
     * c'est la seule place où « pour qui » survit à l'empilement.
     *
     * @param  array<string,mixed>  $payload
     */
    private function titleFor(NotificationType $type, array $payload, ?int $subjectId): string
    {
        $prenom = $subjectId !== null ? ($payload['subject_first_name'] ?? null) : null;

        return $prenom !== null ? $prenom.' · '.$type->label() : $type->label();
    }

    /**
     * Corps = le contexte concret quand le payload le porte, la description du type sinon.
     *
     * La description générique (« Date, horaire ou lieu modifiés ») est redondante avec le titre
     * (« Modification de séance ») : dire QUELLE séance et QUAND vaut mieux que la répéter.
     *
     * @param  array<string,mixed>  $payload
     */
    private function bodyFor(NotificationType $type, array $payload, ?int $subjectId): string
    {
        if ($subjectId !== null && $type === NotificationType::AthleteReactivated) {
            return $this->reactivationBody($payload);
        }

        if (isset($payload['session_title'])) {
            $quand = $this->formatDate($payload['session_start_at'] ?? null);

            return $quand === null ? $payload['session_title'] : $payload['session_title'].' · '.$quand;
        }

        // Récap de série (§4.8) : pas de séance unique, mais un volume et une plage déjà en payload.
        if ($type === NotificationType::CoachTemplateRecap && isset($payload['count'])) {
            $plage = $this->formatDay($payload['from'] ?? null);
            $fin = $this->formatDay($payload['to'] ?? null);

            $resume = $payload['count'].' '.($payload['count'] > 1 ? 'séances' : 'séance');

            return ($plage !== null && $fin !== null) ? $resume.' · '.$plage.' → '.$fin : $resume;
        }

        return $type->description();
    }

    /**
     * Réactivation d'accès lue par le GARANT : la description du type tutoie le sujet (elle sert
     * d'abord de sous-titre dans la matrice de préférences, où le lecteur EST le sujet). Adressée
     * au parent, « Ton accès athlète est réactivé » désigne la mauvaise personne — celle qui lit.
     *
     * Seul type concerné : les autres notifications qui remontent au garant portent une séance, dont
     * le corps prend la place de la description. Un nouveau type sans séance devra passer ici.
     *
     * @param  array<string,mixed>  $payload
     */
    private function reactivationBody(array $payload): string
    {
        $prenom = $payload['subject_first_name'] ?? null;

        // Repli sans prénom : le titre nomme déjà l'enfant, mais une ligne `failed` rejouée après
        // purge n'a plus que l'identifiant — mieux vaut vague que faux.
        return $prenom === null
            ? 'L\'accès athlète de ton enfant est réactivé'
            : 'L\'accès athlète de '.$prenom.' est réactivé';
    }

    /**
     * Date/heure d'une séance au fuseau du club (« sam. 6 sept. · 18:00 »). Conventions CLAUDE.md :
     * contexte dense → `ddd D MMM`, heure → `HH:mm`. La locale est explicite : le rendu se fait au
     * drain, hors requête, et ne doit pas dépendre d'APP_LOCALE.
     */
    private function formatDate(mixed $iso): ?string
    {
        $date = $this->parse($iso);

        return $date === null ? null : $date->isoFormat('ddd D MMM').' · '.$date->format('H:i');
    }

    /**
     * Jour seul, pour les bornes d'une plage de génération (« 6 sept. »).
     *
     * Ces bornes sont des dates NUES (`toDateString()`), pas des instants : les convertir au fuseau
     * du club les faisait reculer d'un jour dès que ce fuseau est négatif — minuit UTC devient la
     * veille 20 h à Guadeloupe, proposée dans les réglages. Une date sans heure n'a pas de fuseau.
     */
    private function formatDay(mixed $iso): ?string
    {
        return $this->parse($iso, instant: false)?->isoFormat('D MMM');
    }

    /**
     * Un payload malformé (ligne ancienne, saisie tierce) ne doit jamais faire échouer un envoi.
     *
     * `$instant` distingue les deux natures de date qui transitent par le payload : un point dans
     * le temps (le début d'une séance, stocké en UTC) se REPOSE au fuseau du club ; une date de
     * calendrier (une borne de plage) s'affiche telle quelle.
     */
    private function parse(mixed $iso, bool $instant = true): ?Carbon
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            $date = Carbon::parse($iso);

            return ($instant ? $date->setTimezone(ClubSettings::current()->timezone) : $date)->locale('fr');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lien profond absolu (push et email s'ouvrent hors contexte de navigation).
     *
     * @param  array<string,mixed>  $payload
     */
    private function urlFor(NotificationType $type, array $payload, NotificationOutbox $line, ?int $subjectId): string
    {
        return match ($type) {
            // Tout ce qui réfère une séance pointe sur sa fiche.
            NotificationType::SessionCancelled,
            NotificationType::SessionRestored,
            NotificationType::SessionModified,
            NotificationType::SessionContent,
            NotificationType::WaitlistPromoted,
            NotificationType::EnrolledByCoach,
            NotificationType::CoachOverride,
            NotificationType::EventCreated,
            NotificationType::NewDebrief,
            NotificationType::CoachRegistration,
            NotificationType::CoachAssigned => isset($payload['session_id'])
                ? route('sessions.show', $this->sessionParams($payload['session_id'], $subjectId))
                : route('planning'),

            // Récap d'une série : pas de séance unique → planning.
            NotificationType::CoachTemplateRecap => route('planning'),

            // Invitations (adhérent §4.1.3, autonomisation §4.2.1) : le token clair voyage dans le
            // payload, le lien ouvre la page d'activation qui consomme le jeton. Même page pour les
            // deux — activer un compte est le même geste, quelle qu'en soit l'origine.
            NotificationType::MemberInvitation,
            NotificationType::GuardianshipInvitation => isset($payload['token'])
                ? route('invitation.activate', $payload['token'])
                : route('login'),

            // Rupture de tutelle : chaque partie va sur SON profil. Surtout pas « Mes enfants » côté
            // garant — le lien vient d'être coupé, l'enfant n'y figure plus, et l'écran serait au
            // mieux déroutant, au pire vide.
            NotificationType::GuardianshipSevered => route('profil'),

            // Réactivation d'accès (§4.4) : lue par le garant d'un mineur, elle parle de l'enfant.
            // L'envoyer sur le dashboard du parent ne lui montrait rien de ce qui a changé.
            NotificationType::AthleteReactivated => $this->reactivationTarget($payload, $line),
        };
    }

    /**
     * Paramètres de la fiche de séance. `?as=` amène le parent sur la fiche AVEC l'enfant pour
     * sujet : sans lui, il arrivait sur une fiche qui parlait de lui alors que la notification
     * parlait de son enfant, et devait rebasculer à la main. SessionShow::mount() valide le ward et
     * redirige vers l'URL canonique.
     *
     * @return array<int|string,mixed>
     */
    private function sessionParams(mixed $sessionId, ?int $subjectId): array
    {
        return $subjectId === null ? [$sessionId] : [$sessionId, 'as' => $subjectId];
    }

    /**
     * `athlete_reactivated` porte l'id du membre réactivé dans `user_id` (clé de payload, à ne pas
     * confondre avec la colonne `user_id` de la ligne, qui porte le DESTINATAIRE). Les deux
     * diffèrent quand c'est le garant qui est notifié pour son enfant (§4.15.5).
     *
     * Oui, `subject_id` répond à la même question et est déjà calculé plus haut : ce n'est PAS un
     * oubli de nettoyage. `user_id` est antérieur au sujet et couvre en plus les lignes émises avant
     * lui, encore en file ou en échec rejouable, qui n'ont pas de `subject_id`. Unifier sur le sujet
     * enverrait ces lignes-là sur le mauvais écran — précisément le défaut corrigé ici.
     *
     * @param  array<string,mixed>  $payload
     */
    private function reactivationTarget(array $payload, NotificationOutbox $line): string
    {
        $membre = $payload['user_id'] ?? null;
        $pourUnEnfant = $membre !== null && $line->user_id !== null && (int) $membre !== $line->user_id;

        return $pourUnEnfant ? route('children') : route('dashboard');
    }
}
