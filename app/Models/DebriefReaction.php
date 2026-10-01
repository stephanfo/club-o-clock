<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Réaction « j'aime » sur un débrief (#101, PRD §4.12.5). Pas de updated_at : on aime ou on retire.
class DebriefReaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['debrief_id', 'user_id'];

    /** @return BelongsTo<Debrief, $this> */
    public function debrief(): BelongsTo
    {
        return $this->belongsTo(Debrief::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
