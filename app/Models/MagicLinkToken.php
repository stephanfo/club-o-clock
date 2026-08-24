<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

// magic_link_tokens — connexion passwordless (PRD §4.1.1). Token stocké hashé, TTL 15 min, usage unique.
class MagicLinkToken extends Model
{
    use Prunable;

    protected $fillable = ['email', 'token_hash', 'code_hash', 'code_attempts', 'expires_at', 'consumed_at'];

    /**
     * Élagage (model:prune, planifié) : tout jeton inutilisable — expiré OU déjà consommé.
     * Borne la croissance de la table (une ligne par demande de lien) et purge l'email résiduel
     * des jetons consommés (minimisation §4.3).
     */
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
        'code_attempts' => 'integer',
    ];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
