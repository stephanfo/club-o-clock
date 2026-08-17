<?php

namespace App\Notifications;

// Registre canonique des types de notif (PRD §4.15.2). Source unique : porte le libellé (matrice
// de préférences §4.15.3, écran outbox §4.15.6) et les canaux éligibles par type. La valeur string
// est ce qui est persisté dans notification_outbox.type et utilisé comme clé de la matrice.
//
// Hors registre : « accès athlète suspendu » (§4.4) — pas d'email/push, bannière in-app seule.
enum NotificationType: string
{
    case SessionCancelled = 'session_cancelled';
    case SessionRestored = 'session_restored';
    case WaitlistPromoted = 'waitlist_promoted';
    case EnrolledByCoach = 'enrolled_by_coach';
    case CoachOverride = 'coach_override';
    case SessionModified = 'session_modified';
    case SessionContent = 'session_content';
    case EventCreated = 'event_created';
    case NewDebrief = 'new_debrief';
    case CoachRegistration = 'coach_registration';
    case CoachAssigned = 'coach_assigned';
    case CoachTemplateRecap = 'coach_template_recap';
    case AthleteReactivated = 'athlete_reactivated';
    case GuardianshipInvitation = 'guardianship_invitation';
    case GuardianshipSevered = 'guardianship_severed';

    /** Libellé FR affiché dans la matrice de préférences (§4.15.3) et l'écran outbox (§4.15.6). */
    public function label(): string
    {
        return match ($this) {
            self::SessionCancelled => 'Annulation de séance',
            self::SessionRestored => 'Réactivation de séance',
            self::WaitlistPromoted => 'Promotion depuis la liste d\'attente',
            self::EnrolledByCoach => 'Inscription par un coach',
            self::CoachOverride => 'Inscription forcée par un coach',
            self::SessionModified => 'Modification de séance',
            self::SessionContent => 'Contenu de séance',
            self::EventCreated => 'Nouvelle compétition ou événement club',
            self::NewDebrief => 'Nouveau débrief',
            self::CoachRegistration => 'Inscription ou désinscription d\'un coach',
            self::CoachAssigned => 'Affectation à une séance',
            self::CoachTemplateRecap => 'Récapitulatif d\'affectations (série)',
            self::AthleteReactivated => 'Compte réactivé',
            self::GuardianshipInvitation => 'Invitation à créer ton compte',
            self::GuardianshipSevered => 'Lien de tutelle rompu',
        };
    }

    /**
     * Canaux éligibles pour ce type. La plupart sont push + email ; « compte réactivé » et
     * « invitation tutelle » sont email seul (§4.15.2 — l'invitation porte le lien d'activation,
     * donc email). Borne le fan-out et les lignes de la matrice de préférences.
     *
     * @return list<'push'|'email'>
     */
    public function channels(): array
    {
        return match ($this) {
            self::AthleteReactivated, self::GuardianshipInvitation => ['email'],
            default => ['push', 'email'],
        };
    }

    /** Sous-titre explicatif affiché sous le libellé dans la matrice de préférences (§4.15.3). */
    public function description(): string
    {
        return match ($this) {
            self::SessionCancelled => 'Une séance à laquelle tu es inscrit·e est annulée',
            self::SessionRestored => 'Une séance annulée est réactivée',
            self::WaitlistPromoted => 'Une place se libère, tu passes inscrit·e (files A, B ou C — un seul réglage pour les trois)',
            self::EnrolledByCoach => 'Un coach t\'inscrit sur une séance à ta place',
            self::CoachOverride => 'Un coach t\'inscrit d\'office sur une séance (override)',
            self::SessionModified => 'Date, horaire ou lieu modifiés',
            self::SessionContent => 'Texte, parcours ou météo ajoutés ou modifiés',
            self::EventCreated => 'Compétition ou événement club créé dans ta catégorie',
            self::NewDebrief => 'Un participant publie un débrief sur une compétition',
            self::CoachRegistration => 'Un coach rejoint ou quitte une séance que tu encadres',
            self::CoachAssigned => 'Tu es affecté·e comme encadrant·e d\'une séance',
            self::CoachTemplateRecap => 'Récapitulatif de tes affectations sur une série de séances',
            self::AthleteReactivated => 'Ton accès athlète est réactivé',
            self::GuardianshipInvitation => 'Un compte autonome t\'est ouvert : active-le via le lien reçu',
            self::GuardianshipSevered => 'Le lien de tutelle a été rompu',
        };
    }

    /**
     * Groupes de la matrice de préférences (§4.15.3), dans l'ordre d'affichage. Chaque groupe
     * porte les types qu'un utilisateur peut moduler ; `coachOnly` masque le groupe aux non-coachs.
     * Hors matrice (notifs transactionnelles, toujours émises) : `athlete_reactivated`,
     * `guardianship_invitation` et `guardianship_severed`.
     *
     * @return list<array{label:string,coachOnly:bool,types:list<self>}>
     */
    public static function matrixGroups(): array
    {
        return [
            ['label' => 'Mes séances', 'coachOnly' => false, 'types' => [
                self::SessionCancelled, self::SessionRestored, self::WaitlistPromoted,
                self::EnrolledByCoach, self::CoachOverride, self::SessionModified, self::SessionContent,
            ]],
            ['label' => 'Le club', 'coachOnly' => false, 'types' => [
                self::EventCreated, self::NewDebrief,
            ]],
            ['label' => 'Encadrement', 'coachOnly' => true, 'types' => [
                self::CoachRegistration, self::CoachAssigned, self::CoachTemplateRecap,
            ]],
        ];
    }
}
