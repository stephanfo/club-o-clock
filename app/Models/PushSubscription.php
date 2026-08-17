<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// push_subscriptions — un abonnement Web Push (un appareil) d'un utilisateur (J8.6, cadrage §6.3).
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'content_encoding', 'user_agent',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Empreinte stable d'un endpoint, support de la déduplication (un endpoint = un abonnement). */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
