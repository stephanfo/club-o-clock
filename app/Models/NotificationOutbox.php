<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// notification_outbox — file d'envois persistée (cadrage §7.14). Drain branché J8.
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    public const STATUSES = ['pending', 'sent', 'failed', 'cancelled'];

    /** Libellés FR des statuts pour l'écran de gestion (§4.15.6). */
    public const STATUS_LABELS = [
        'pending' => 'En attente',
        'sent' => 'Envoyée',
        'failed' => 'En échec',
        'cancelled' => 'Annulée',
    ];

    protected $fillable = [
        'type', 'channel', 'payload', 'user_id', 'status', 'attempts', 'available_at', 'sent_at', 'read_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alertes visibles par l'utilisateur sur la page Alertes (push envoyés, 60 j) —
     * base commune du listing, du badge de non-lus et du marquage lu (revue UX 2026-07-11).
     *
     * @return Builder<self>
     */
    public static function alertsFor(int $userId): Builder
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('status', 'sent')
            ->where('channel', 'push')
            ->where('created_at', '>=', now()->subDays(60));
    }

    /** @var array<int,int> cache par requête du compteur de non-lus (badge rendu 2×/page : sidebar + cloche mobile). */
    private static array $unreadCache = [];

    /** Nombre d'alertes non lues (badge cloche + nav). Mémoïsé par requête (cf. ClubSettings::current). */
    public static function unreadCountFor(int $userId): int
    {
        return self::$unreadCache[$userId] ??= self::alertsFor($userId)->whereNull('read_at')->count();
    }

    /** Invalide le cache après un marquage lu en masse (page Alertes) pour éviter un badge périmé. */
    public static function forgetUnreadCount(?int $userId = null): void
    {
        if ($userId === null) {
            self::$unreadCache = [];
        } else {
            unset(self::$unreadCache[$userId]);
        }
    }
}
