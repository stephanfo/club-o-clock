<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// AuditLog (PRD §5.1, §4.18). Actions sensibles. FK actor/target anonymisables.
class AuditLog extends Model
{
    public const UPDATED_AT = null; // un log ne se met jamais à jour

    protected $fillable = [
        'actor_id', 'actor_role', 'action', 'target_type', 'target_id',
        'session_id', 'motif', 'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Séance liée (override quota, cancel/restore, génération…) — pour le filtrage stats par discipline.
     *
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
