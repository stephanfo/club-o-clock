<?php

namespace App\Notifications;

use App\Models\NotificationOutbox;

// Rend une ligne d'outbox en contenu présentable (titre, corps, lien profond), partagé par les
// canaux réels (push + email) — un seul endroit pour dériver l'affichage du couple type + payload.
// Rendu GÉNÉRIQUE (décision J8.6) : titre = libellé du type, corps = description du type, lien
// déduit de l'id porté par le payload. Aucun chargement d'entité à l'envoi (robuste, pas de N+1 au
// drain) ; l'entité peut avoir disparu entre l'émission et l'envoi sans casser la notification.
class NotificationRenderer
{
    /**
     * @return array{title:string,body:string,url:string}
     */
    public function render(NotificationOutbox $line): array
    {
        $type = NotificationType::from($line->type);
        $payload = $line->payload ?? [];

        return [
            'title' => $type->label(),
            'body' => $type->description(),
            'url' => $this->urlFor($type, $payload),
        ];
    }

    /**
     * Lien profond absolu (push et email s'ouvrent hors contexte de navigation).
     *
     * @param  array<string,mixed>  $payload
     */
    private function urlFor(NotificationType $type, array $payload): string
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
                ? route('sessions.show', $payload['session_id'])
                : route('planning'),

            // Récap d'une série : pas de séance unique → planning.
            NotificationType::CoachTemplateRecap => route('planning'),

            // Invitation d'autonomisation : le token clair voyage dans le payload (§4.2.1), le lien
            // ouvre la page d'activation qui consomme le jeton.
            NotificationType::GuardianshipInvitation => isset($payload['token'])
                ? route('guardianship.activate', $payload['token'])
                : route('login'),

            NotificationType::GuardianshipSevered => route('profil'),
            NotificationType::AthleteReactivated => route('dashboard'),
        };
    }
}
