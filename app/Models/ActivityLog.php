<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ActivityLog (PRD §5.1, §4.18). actor peut valoir « system » (FK nulle + flag).
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_is_system', 'action', 'user_id', 'session_id',
        'registration_id', 'resulting_status', 'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'actor_is_system' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
