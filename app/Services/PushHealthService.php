<?php

namespace App\Services;

use App\Models\NotificationOutbox;
use App\Models\PushSubscription;
use Illuminate\Support\Carbon;

// Santé du push côté admin (#97) : le bureau doit repérer une panne (clés VAPID, service push)
// avant les adhérents. Lu sur l'écran des envois, là où l'admin vient quand il doute d'un envoi.
class PushHealthService
{
    /** Fenêtre d'observation des envois récents. */
    public const WINDOW_HOURS = 24;

    /** En dessous de ce nombre d'essais, un taux ne veut rien dire : pas d'alerte. */
    public const MIN_SAMPLE = 5;

    /** Part d'échecs à partir de laquelle on parle d'échecs massifs. */
    public const ALERT_RATE = 0.5;

    /**
     * `failing` compte les essais qui n'ont pas abouti : lignes `failed` et lignes encore en file
     * après au moins un échec. `no_target` est affiché à part et n'entre pas dans l'alerte : un
     * adhérent sans appareil abonné (iPhone sans PWA installée) est un cas normal, pas une panne.
     *
     * @return array{devices:int,devicesFailing:int,delivered:int,failing:int,noTarget:int,rate:?float,alert:bool}
     */
    public function summary(): array
    {
        $since = Carbon::now()->subHours(self::WINDOW_HOURS);
        $push = fn () => NotificationOutbox::query()->where('channel', 'push')->where('updated_at', '>=', $since);

        $delivered = $push()->where('status', 'sent')->count();
        $failing = $push()->where(fn ($q) => $q->where('status', 'failed')
            ->orWhere(fn ($q) => $q->where('status', 'pending')->where('attempts', '>', 0)))->count();
        $noTarget = $push()->where('status', 'no_target')->count();

        $tries = $delivered + $failing;
        $rate = $tries > 0 ? $failing / $tries : null;

        return [
            'devices' => PushSubscription::count(),
            'devicesFailing' => PushSubscription::where('failure_count', '>', 0)->count(),
            'delivered' => $delivered,
            'failing' => $failing,
            'noTarget' => $noTarget,
            'rate' => $rate,
            'alert' => $tries >= self::MIN_SAMPLE && $rate >= self::ALERT_RATE,
        ];
    }
}
