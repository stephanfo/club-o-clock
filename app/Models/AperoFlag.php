<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// AperoFlag (PRD §5.1, §4.14). Hard delete au retrait, SAUF la cascade d'annulation de séance :
// le flag est alors « garé » (parked_at non nul) pour être restaurable avec son motif (§4.14.4).
class AperoFlag extends Model
{
    public $timestamps = false; // flagged_at porte l'horodatage

    protected $fillable = [
        'session_id', 'user_id', 'registration_id', 'motif', 'flagged_at', 'flagged_by', 'parked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'flagged_at' => 'datetime',
        'parked_at' => 'datetime',
    ];

    /** Flag actif = non garé (un flag garé est inactif mais restaurable). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('parked_at');
    }

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

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
