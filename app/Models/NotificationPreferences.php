<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// NotificationPreferences — 1:1 User (PRD §5.1, §4.15). Matrice type×canal + pause globale.
class NotificationPreferences extends Model
{
    protected $table = 'notification_preferences';

    protected $fillable = ['user_id', 'matrix', 'paused'];

    /** @var array<string, string> */
    protected $casts = [
        'matrix' => 'array',
        'paused' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
