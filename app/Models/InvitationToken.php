<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// invitation_tokens — lien d'activation (PRD §4.1.3, §4.2.1). TTL = ClubSettings.invitation_link_days.
class InvitationToken extends Model
{
    use Prunable;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'consumed_at'];

    /** Élagage (model:prune, planifié) : jetons d'activation expirés OU déjà consommés. */
    public function prunable(): Builder
    {
        return static::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('consumed_at');
    }

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
