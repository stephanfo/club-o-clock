<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;

// Émission des événements de séance (§4.7) vers les inscrits actifs : modification, annulation,
// restauration. Fan-out via le dispatcher (routage parent/enfant + matrice + pause appliqués par
// destinataire) ; relations préchargées pour éviter le N+1 sur une séance à N participants.
class SessionNotificationService
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private OutboxDrainer $drainer,
    ) {}

    /**
     * Notifie tous les `participating` d'un événement séance. À appeler APRÈS commit du changement.
     * Si $priority (case « envoi prioritaire » §4.7), draine aussitôt les lignes créées au lieu
     * d'attendre le lot cron — même chemin de drain (§7.14). $excludeUserId retire un inscrit du
     * fan-out (ex. l'auteur d'un débrief ne se notifie pas lui-même — §4.12.5).
     */
    public function notifyParticipants(Session $session, NotificationType $type, bool $priority = false, ?int $excludeUserId = null): void
    {
        $participants = Registration::query()
            ->where('session_id', $session->id)
            ->where('status', 'participating')
            ->when($excludeUserId !== null, fn ($q) => $q->where('user_id', '!=', $excludeUserId))
            ->with(['user.guardian.notificationPreferences', 'user.notificationPreferences'])
            ->get();

        $created = collect();

        foreach ($participants as $registration) {
            if ($registration->user === null) {
                continue;
            }

            $created = $created->merge(
                $this->dispatcher->dispatch($type, $registration->user, ['session_id' => $session->id])
            );
        }

        if ($priority && $created->isNotEmpty()) {
            $this->drainer->drainNow($created);
        }
    }

    /**
     * Annonce une compétition / un événement club nouvellement créé (§4.7, type event_created) à sa
     * catégorie cible : les membres actifs dont une catégorie recoupe celles de la séance. Sans
     * catégorie ciblée, toute la base active est concernée (événement ouvert à tous). Le créateur est
     * exclu. Chaque destinataire est ensuite routé/filtré par le dispatcher (parent-enfant, matrice,
     * pause). À appeler APRÈS commit de la création.
     */
    public function notifyEventCreated(Session $session): void
    {
        $categoryIds = $session->categories()->pluck('categories.id')->all();

        $audience = User::query()
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->where('id', '!=', $session->created_by)
            ->when($categoryIds !== [], fn ($q) => $q->whereHas(
                'categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)
            ))
            ->with(['guardian.notificationPreferences', 'notificationPreferences'])
            ->get();

        foreach ($audience as $user) {
            $this->dispatcher->dispatch(NotificationType::EventCreated, $user, ['session_id' => $session->id]);
        }
    }
}
