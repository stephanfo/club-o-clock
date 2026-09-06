<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
use App\Support\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Suppression définitive d'une séance (PRD §4.7).
 *
 * Le seul geste de l'application qui détruise pour de bon. L'annulation, elle, est un soft flag
 * réversible : c'est le geste normal quand une séance n'a pas lieu. La suppression répond à un
 * autre besoin — la ligne n'aurait jamais dû exister (doublon, erreur de saisie) —, et sa raison
 * d'être est justement de ne rien laisser derrière.
 *
 * Les cascades SQL font l'essentiel : inscriptions, encadrement, catégories, flags apéro et
 * débriefs partent avec la séance ; audit_logs.session_id et activity_logs.session_id passent à
 * NULL. Restent deux choses que la base ne sait pas faire seule, et qui sont tout l'objet de cette
 * classe : préserver la lisibilité des alertes déjà envoyées, et laisser une trace qui survive.
 */
class SessionDeletionService
{
    /**
     * Supprime la séance et rend cohérent ce qui lui survit.
     *
     * @throws RuntimeException si la séance n'est pas annulée, ou porte un débrief
     */
    public function delete(Session $session, User $actor): void
    {
        // Garde SERVEUR, doublon volontaire de la policy : un service destructif ne se repose pas
        // sur son appelant. Même raison qu'ailleurs — l'état vient du client.
        if (! $session->isCancelled()) {
            throw new RuntimeException('Une séance doit être annulée avant de pouvoir être supprimée.');
        }

        // Un débrief est du texte écrit par un membre, pas une donnée de gestion : la cascade
        // l'emporterait sans que l'admin l'ait jamais lu. Même doctrine que les parcours (§4.20),
        // dont l'usage par une séance interdit la suppression dure.
        $debriefs = $session->debriefs()->count();
        if ($debriefs > 0) {
            throw new RuntimeException(
                'Cette séance porte '.$debriefs.' débrief'.($debriefs > 1 ? 's' : '')
                .' — supprimer la séance les effacerait. Archive-les d\'abord.'
            );
        }

        $identite = self::identite($session);
        $id = $session->id;

        DB::transaction(function () use ($session, $actor, $identite, $id) {
            $this->delierAlertes($session);

            // AVANT le DELETE, et surtout SANS session_id : cette colonne est en ON DELETE SET NULL,
            // la ligne perdrait sa cible dans la seconde. target_type/target_id n'ont, eux, aucune
            // clé étrangère — seulement un index —, donc ils survivent. Le motif porte l'identité en
            // clair : c'est la seule chose qui restera lisible une fois la séance disparue.
            AuditLogger::record('delete_session', $actor, [
                'target_type' => 'session',
                'target_id' => $id,
                'motif' => $identite,
            ]);

            $session->delete();
        });
    }

    /**
     * Ce qui bloque la suppression, pour l'afficher au lieu de le découvrir au clic.
     *
     * @return array{debriefs:int}
     */
    public static function blocages(Session $session): array
    {
        return ['debriefs' => $session->debriefs()->count()];
    }

    /** Décompte de ce qui partira, pour chiffrer les conséquences dans le dialog. */
    public static function alertesLiees(Session $session): int
    {
        return self::requeteAlertes($session)->count();
    }

    /**
     * Les alertes déjà envoyées ne sont rattachées à la séance QUE par un `session_id` posé dans
     * leur payload JSON : aucune clé étrangère, donc aucune cascade. Sans traitement, elles
     * survivraient en pointant le vide — et beaucoup ne portent que `{"session_id": N}`, si bien
     * qu'Alerts les rendrait sans titre, avec un lien vers un 404.
     *
     * On les rend donc autonomes avant de couper : le titre et le créneau sont figés dans le
     * payload (le repli que Alerts::sousTitre prévoit déjà pour ce cas exact), puis `session_id`
     * est retiré — ce qui fait disparaître le lien plutôt que de le laisser mener nulle part.
     * L'alerte reste vraie : elle a bien été envoyée, elle dit toujours de quoi elle parlait.
     */
    private function delierAlertes(Session $session): void
    {
        foreach (self::requeteAlertes($session)->get() as $ligne) {
            $payload = $ligne->payload ?? [];

            $payload['session_title'] ??= $session->title;
            $payload['session_start_at'] ??= $session->start_at?->toIso8601String();
            unset($payload['session_id']);

            $ligne->forceFill(['payload' => $payload])->save();
        }
    }

    /** @return Builder<NotificationOutbox> */
    private static function requeteAlertes(Session $session)
    {
        // JSON_EXTRACT plutôt que whereJsonContains : la clé peut manquer, et on compare un scalaire.
        return NotificationOutbox::query()
            ->whereRaw("JSON_EXTRACT(payload, '$.session_id') = ?", [$session->id]);
    }

    /**
     * « Titre · dim 26 sept. 2026 · 13:00 » — l'identité que l'audit gardera de la séance.
     *
     * À l'heure du club et non en UTC : ce texte se lit dans le journal, pas dans une requête.
     * Tronqué à 255, la largeur de `audit_logs.motif`.
     */
    private static function identite(Session $session): string
    {
        $quand = $session->start_at
            ?->copy()->setTimezone(ClubSettings::current()->timezone)
            ->locale('fr')->isoFormat('ddd D MMM YYYY · HH:mm');

        return mb_substr($session->title.($quand !== null ? ' · '.$quand : ''), 0, 255);
    }
}
