<?php

namespace App\Support\Logging;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;

// Helper de traçabilité Activity (PRD §4.18, invariant J0 ROADMAP_DEV §28).
// L'acteur peut être « system » (FK nulle + flag actor_is_system) pour les actions automatiques.
class ActivityLogger
{
    /**
     * @param  array{user_id?:int,session_id?:int,registration_id?:int,resulting_status?:string}  $context
     */
    public static function record(string $action, ?User $actor, array $context = []): ActivityLog
    {
        return ActivityLog::create([
            'actor_id' => $actor?->id,
            'actor_is_system' => $actor === null,
            'action' => $action,
            'user_id' => $context['user_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'registration_id' => $context['registration_id'] ?? null,
            'resulting_status' => $context['resulting_status'] ?? null,
            'created_at' => Carbon::now(),
        ]);
    }

    /** Raccourci pour une action système (actor null + flag). */
    public static function system(string $action, array $context = []): ActivityLog
    {
        return self::record($action, null, $context);
    }
}
