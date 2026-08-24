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

    /**
     * Élagage (model:prune, planifié) : jetons expirés SANS avoir été consommés — eux seuls sont
     * des déchets.
     *
     * Un jeton consommé est CONSERVÉ : il est le marqueur durable « ce compte a été activé un jour »,
     * que lit l'invitation de masse pour ne pas re-solliciter quelqu'un déjà entré. Aucun coût de
     * minimisation (§4.3) — contrairement à MagicLinkToken, cette table ne stocke aucune donnée
     * personnelle : un `user_id` et un hash inversable par personne. La croissance est bornée à une
     * ligne par adhérent activé.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('consumed_at')
            ->where('expires_at', '<', now());
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
