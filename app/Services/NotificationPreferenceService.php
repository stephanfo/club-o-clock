<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\NotificationPreferences;
use App\Models\User;
use App\Notifications\NotificationType;

// Préférences de notification d'un utilisateur (matrice type×canal §4.15.3 + pause globale §4.15.4).
// Lecture/écriture de la matrice consommée par le NotificationDispatcher (qui applique `?? true` :
// défaut tout activé, opt-out cellule par cellule). Aucune écriture hors actions explicites de l'user.
class NotificationPreferenceService
{
    /**
     * Préférences de l'utilisateur, créées à la volée au défaut (matrice vide = tout activé).
     * `firstOrCreate` sur la relation : requête fraîche à chaque appel, jamais de doublon même si
     * la relation lazy a déjà été résolue à null avant la création.
     */
    public function forUser(User $user): NotificationPreferences
    {
        return $user->notificationPreferences()->firstOrCreate([], ['matrix' => [], 'paused' => false]);
    }

    /**
     * État d'affichage complet en une seule lecture (un `forUser`) : pause + matrice normalisée des
     * types VISIBLES, chaque cellule éligible valant le réglage stocké (défaut `true`), plus l'état
     * des interrupteurs de canal du club.
     *
     * @return array{paused:bool,matrix:array<string,array<string,bool>>,clubChannels:array<string,bool>}
     */
    public function state(User $user): array
    {
        $prefs = $this->forUser($user);
        $stored = $prefs->matrix ?? [];
        $matrix = [];

        foreach ($this->visibleTypes($user) as $type) {
            foreach ($type->channels() as $channel) {
                $matrix[$type->value][$channel] = (bool) ($stored[$type->value][$channel] ?? true);
            }
        }

        $settings = ClubSettings::current();

        return [
            'paused' => (bool) $prefs->paused,
            'matrix' => $matrix,
            // Canaux ouverts au niveau club (§4.17) : la colonne correspondante reste AFFICHÉE mais
            // inerte quand le club l'a coupée. La matrice stockée n'est pas touchée — le réglage
            // individuel reprend effet tel quel si le bureau réactive le canal.
            'clubChannels' => [
                'push' => $settings->channelEnabled('push'),
                'email' => $settings->channelEnabled('email'),
            ],
        ];
    }

    /**
     * Groupes de la matrice visibles pour cet utilisateur (coach-only filtré). Source unique du
     * périmètre « types réglables » — consommée par le rendu ET par la validation de `setCell`.
     *
     * @return list<array{label:string,coachOnly:bool,types:list<NotificationType>}>
     */
    public function visibleGroups(User $user): array
    {
        $isCoach = $user->hasRole('coach') || $user->hasRole('admin');

        return array_values(array_filter(
            NotificationType::matrixGroups(),
            fn ($g) => ! $g['coachOnly'] || $isCoach,
        ));
    }

    /** Bascule une cellule (type, canal) et persiste. Ignore une cellule non éligible/non visible. */
    public function setCell(User $user, string $typeValue, string $channel, bool $on): void
    {
        $type = NotificationType::tryFrom($typeValue);
        if ($type === null
            || ! in_array($type, $this->visibleTypes($user), true)
            || ! in_array($channel, $type->channels(), true)) {
            return;
        }

        $prefs = $this->forUser($user);
        $matrix = $prefs->matrix ?? [];
        $matrix[$typeValue][$channel] = $on;
        $prefs->update(['matrix' => $matrix]);
    }

    /** Pause globale (§4.15.4) : interrupteur master, coupe tous les canaux jusqu'à réactivation. */
    public function setPaused(User $user, bool $paused): void
    {
        $this->forUser($user)->update(['paused' => $paused]);
    }

    /**
     * Types réglables (groupes visibles aplatis) — dérivé de `visibleGroups` pour garder une source
     * unique entre rendu et validation.
     *
     * @return list<NotificationType>
     */
    private function visibleTypes(User $user): array
    {
        $types = [];

        foreach ($this->visibleGroups($user) as $group) {
            array_push($types, ...$group['types']);
        }

        return $types;
    }
}
