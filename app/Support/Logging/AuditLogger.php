<?php

namespace App\Support\Logging;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

// Helper de traçabilité Audit (PRD §4.18, invariant J0 ROADMAP_DEV §28).
// Disponible dès J0, appelé au fil de l'eau à chaque action sensible. Capture un snapshot
// du rôle de l'acteur (actor_role) pour survivre à l'anonymisation RGPD du compte (§4.3).
class AuditLogger
{
    /**
     * @param  array{target_type?:string,target_id?:int,session_id?:int,motif?:string}  $context
     */
    public static function record(string $action, ?User $actor, array $context = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor ? implode(',', $actor->roles ?? []) : null,
            'action' => $action,
            'target_type' => $context['target_type'] ?? null,
            'target_id' => $context['target_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'motif' => $context['motif'] ?? null,
            'created_at' => Carbon::now(),
        ]);
    }
}
