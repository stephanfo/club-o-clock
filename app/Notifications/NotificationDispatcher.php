<?php

namespace App\Notifications;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

// Émetteur unique (cadrage §7.14). Une action métier appelle dispatch() ; la requête ne fait
// qu'INSÉRER des lignes d'outbox (rapide même en fan-out), le drain envoie réellement. Un seul
// chemin pour appliquer les interrupteurs de canal du club (§4.17), le routage parent/enfant
// (§4.15.5), la matrice (§4.15.3) et la pause (§4.15.4).
//
// Conséquence assumée du filtrage à l'émission : une notification émise pendant qu'un canal est
// coupé est PERDUE pour ce canal — il n'y a rien à rejouer si le club le réactive. L'alternative
// (créer les lignes puis les bloquer) remplirait l'outbox d'alertes qui, une fois libérées,
// arriveraient périmées ; le drain garde néanmoins une garde pour les lignes déjà en file.
class NotificationDispatcher
{
    /**
     * Fan-out d'une notif sur le ou les destinataires déduits du sujet → lignes d'outbox pending.
     *
     * @param  array<string,mixed>  $payload  contenu d'affichage (référence le sujet, pas le destinataire)
     * @param  list<'push'|'email'>|null  $channels  restreint les canaux (défaut : canaux du type)
     * @return Collection<int,NotificationOutbox> lignes créées (vide si tout est filtré)
     */
    public function dispatch(NotificationType $type, User $subject, array $payload = [], ?array $channels = null): Collection
    {
        $channels = $this->resolveChannels($type, $channels);
        $created = collect();

        $recipients = $this->recipients($subject);

        // Zéro destinataire joignable (ex. P1 dont le garant est désactivé/anonymisé) : on trace
        // au lieu de perdre l'événement en silence — l'admin peut alors reparenter (§4.2).
        if ($recipients === []) {
            Log::warning('Notification sans destinataire joignable', [
                'type' => $type->value,
                'subject_id' => $subject->id,
            ]);
        }

        foreach ($recipients as $recipient) {
            $created = $created->merge($this->linesFor($type, $recipient, $channels, $payload));
        }

        return $created;
    }

    /**
     * Variante CIBLÉE : crée les lignes pour exactement $recipient, SANS routage parent/enfant.
     * Pour les notifs adressées à une personne précise et non à « l'athlète et ses parents » — ex.
     * le lien d'activation d'une invitation tutelle, qui ne doit pas partir au garant (§4.2.1), ou
     * la rupture de tutelle, où le lien est déjà coupé et chaque partie est notifiée explicitement.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<'push'|'email'>|null  $channels
     * @return Collection<int,NotificationOutbox>
     */
    public function dispatchTo(NotificationType $type, User $recipient, array $payload = [], ?array $channels = null): Collection
    {
        return $this->linesFor($type, $recipient, $this->resolveChannels($type, $channels), $payload);
    }

    /** Canaux demandés bornés à ceux supportés par le type (ex. « compte réactivé » = email seul). */
    private function resolveChannels(NotificationType $type, ?array $channels): array
    {
        return array_values(array_intersect($channels ?? $type->channels(), $type->channels()));
    }

    /**
     * Crée les lignes d'outbox d'un destinataire unique : interrupteur de canal du club (§4.17),
     * pause globale (§4.15.4), matrice §4.15.3 (défaut tout activé, opt-out cellule par cellule)
     * et présence d'adresse pour l'email.
     *
     * @param  list<'push'|'email'>  $channels
     * @param  array<string,mixed>  $payload
     * @return Collection<int,NotificationOutbox>
     */
    private function linesFor(NotificationType $type, User $recipient, array $channels, array $payload): Collection
    {
        $created = collect();
        // Les fan-outs préchargent les prefs (with) ; les envois unitaires (génération de modèles,
        // tutelle…) arrivent sans — chargement explicite si absent plutôt que lazy-load.
        $prefs = $recipient->loadMissing('notificationPreferences')->notificationPreferences;

        // Pause globale (§4.15.4) : interrupteur master, coupe tous les canaux de ce destinataire.
        if ($prefs?->paused) {
            return $created;
        }

        $matrix = $prefs?->matrix ?? [];
        $now = Carbon::now();
        // Singleton mémoïsé par requête : lecture hors boucle, aucune requête dans le fan-out.
        $settings = ClubSettings::current();

        foreach ($channels as $channel) {
            // Interrupteur club (§4.17) : canal fermé → aucune ligne créée. Filtre en amont de la
            // préférence individuelle, donc l'outbox ne contient que ce qui est réellement
            // envoyable — pas de ligne marquée « envoyée » alors que rien n'est parti.
            if (! $settings->channelEnabled($channel)) {
                continue;
            }

            // Pas d'adresse → pas d'email possible (cas mineur P1 sans compte propre, etc.).
            if ($channel === 'email' && $recipient->email === null) {
                continue;
            }

            // Matrice §4.15.3 : défaut tout activé, opt-out cellule par cellule.
            if (! (bool) ($matrix[$type->value][$channel] ?? true)) {
                continue;
            }

            $created->push(NotificationOutbox::create([
                'type' => $type->value,
                'channel' => $channel,
                'payload' => $payload,
                'user_id' => $recipient->id,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
            ]));
        }

        return $created;
    }

    /**
     * Routage parent/enfant (§4.15.5), phase déduite de (guardian_id, email) — pas de champ stocké :
     *   P1 (mineur sans compte propre) → garant seul ;
     *   P2 (compte propre + lien actif) → enfant ET garant ;
     *   P3 / adulte (pas de garant)    → l'intéressé seul.
     * Chaque destinataire est ensuite évalué contre SA propre matrice / pause (§4.15.5).
     *
     * @return list<User>
     */
    private function recipients(User $subject): array
    {
        if ($subject->guardian_id === null) {
            return $this->reachable($subject) ? [$subject] : [];
        }

        // Avec garant : l'enfant n'est destinataire que s'il a son propre compte (email) = P2.
        $recipients = ($subject->email !== null && $this->reachable($subject)) ? [$subject] : [];

        if ($subject->guardian !== null && $this->reachable($subject->guardian)) {
            $recipients[] = $subject->guardian;
        }

        return $recipients;
    }

    /**
     * Un destinataire anonymisé (tombstone RGPD) ou désactivé n'est plus joignable : on ne lui
     * adresse plus rien via le routage auto. dispatchTo() reste volontairement SANS ce filtre
     * (adressage explicite — ex. notif de rupture de tutelle pendant une suppression de compte).
     */
    private function reachable(User $recipient): bool
    {
        return $recipient->anonymized_at === null && $recipient->is_active;
    }
}
