<?php

namespace App\Services;

use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Notifications\OutboxDrainer;
use App\Support\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Supervision/rattrapage de l'outbox (écran bureau §4.15.6). Réutilise le chemin de drain unique
// (J8.1) ; n'agit que sur des actions explicites de l'admin (annuler / pousser / rejouer), chacune
// tracée en AuditLog. La simple consultation n'écrit rien (filtres ci-dessous = lecture pure).
class OutboxAdminService
{
    /** Plafond d'un envoi manuel synchrone : borne l'I/O en requête web (le reste au prochain clic/cron). */
    public const PUSH_BATCH_CAP = 200;

    public function __construct(private OutboxDrainer $drainer) {}

    /**
     * Liste filtrée (§4.15.6 : statut, canal, type, destinataire), fenêtre « charger plus » comme
     * la page Journaux. Filtres null = ignorés.
     *
     * @param  array{status?:?string,channel?:?string,type?:?string,user_id?:?int}  $filters
     * @return array{rows:Collection<int,NotificationOutbox>,total:int}
     */
    public function page(array $filters, int $perPage = 25): array
    {
        $base = $this->query($filters);

        return [
            'rows' => (clone $base)->with('user')->latest('id')->limit($perPage)->get(),
            'total' => (clone $base)->count(),
        ];
    }

    /**
     * @param  array{status?:?string,channel?:?string,type?:?string,user_id?:?int}  $filters
     * @return Builder<NotificationOutbox>
     */
    private function query(array $filters): Builder
    {
        return NotificationOutbox::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v));
    }

    /**
     * Annulation (rattrapage §4.15.6) : passe en `cancelled` les lignes encore `pending` parmi
     * celles fournies — sort du périmètre du drain. Renvoie le nombre effectivement annulé.
     *
     * @param  list<int>  $ids
     */
    public function cancel(array $ids, User $admin): int
    {
        if ($ids === []) {
            return 0;
        }

        $count = NotificationOutbox::query()
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        if ($count > 0) {
            AuditLogger::record('outbox_cancelled', $admin, ['motif' => "{$count} envoi(s)"]);
        }

        return $count;
    }

    /**
     * Rejeu des échecs (§4.15.6) : remet `pending`, `available_at` immédiat et RÉINITIALISE
     * `attempts` (sinon le 1er échec rebascule aussitôt en `failed`). Drainé au prochain cron,
     * ou tout de suite via pushNow. Renvoie le nombre remis en file.
     *
     * @param  list<int>  $ids
     */
    public function retry(array $ids, User $admin): int
    {
        if ($ids === []) {
            return 0;
        }

        $count = NotificationOutbox::query()
            ->whereIn('id', $ids)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'available_at' => Carbon::now()]);

        if ($count > 0) {
            AuditLogger::record('outbox_retried', $admin, ['motif' => "{$count} envoi(s)"]);
        }

        return $count;
    }

    /**
     * Envoi manuel immédiat (§4.15.6) : draine tout de suite les lignes `pending` fournies, sans
     * attendre le lot cron — même chemin que l'envoi prioritaire (§7.14). Renvoie le compte d'envois.
     *
     * @param  list<int>  $ids
     * @return array{sent:int,retried:int,failed:int,cancelled:int}
     */
    public function pushNow(array $ids, User $admin): array
    {
        if ($ids === []) {
            return ['sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0];
        }

        $lines = NotificationOutbox::query()
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->limit(self::PUSH_BATCH_CAP)
            ->get();

        $stats = $this->drainer->drainNow($lines);

        if ($lines->isNotEmpty()) {
            AuditLogger::record('outbox_pushed', $admin, ['motif' => "{$lines->count()} envoi(s)"]);
        }

        return $stats;
    }

    /**
     * Ids des lignes `pending` correspondant aux filtres — cible des actions « tous les pending ».
     * L'union `['status'=>'pending'] + $filters` force le statut : la clé de gauche l'emporte, donc
     * un éventuel filtre `status` de l'écran (ex. « sent ») est ignoré ici au profit de `pending`.
     */
    public function pendingIds(array $filters): array
    {
        return $this->query(['status' => 'pending'] + $filters)->pluck('id')->all();
    }

    /** Types présents pour alimenter le filtre (libellés via NotificationType). @return list<string> */
    public function typeOptions(): array
    {
        return array_map(fn (NotificationType $t) => $t->value, NotificationType::cases());
    }
}
