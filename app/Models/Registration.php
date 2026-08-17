<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Entité Registration (PRD §5.1, §4.9).
class Registration extends Model
{
    public const STATUSES = ['participating', 'waitlist', 'cancelled'];

    public const WAITLIST_REASONS = ['capacity', 'quota_exceeded'];

    protected $fillable = [
        'session_id', 'user_id', 'status', 'waitlist_reason', 'waitlist_position',
        'registered_at', 'promoted_at', 'promoted_by', 'override_by', 'override_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'waitlist_position' => 'integer',
        'registered_at' => 'datetime',
        'promoted_at' => 'datetime',
    ];

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
