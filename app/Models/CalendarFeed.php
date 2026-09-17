<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Adresse d'abonnement agenda d'un adhérent (#39, PRD §4.21.2). Au plus une ligne active par compte ;
 * les lignes révoquées restent pour distinguer « révoqué » (410) d'« inconnu » (404).
 */
class CalendarFeed extends Model
{
    /** Rappels proposés : valeur stockée => libellé. */
    public const RAPPELS = ['' => 'Aucun', '60' => '1 h avant', '120' => '2 h avant', 'veille' => 'La veille au soir'];

    protected $fillable = ['user_id', 'token', 'include_waitlist', 'include_coaching', 'include_wards', 'reminder'];

    protected $casts = [
        'include_waitlist' => 'boolean',
        'include_coaching' => 'boolean',
        'include_wards' => 'boolean',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<CalendarFeed> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Rappel au format attendu par Ics::event() : minutes, Ics::RAPPEL_VEILLE, ou null. */
    public function rappel(): int|string|null
    {
        return match ($this->reminder) {
            null, '' => null,
            'veille' => 'veille',
            default => (int) $this->reminder,
        };
    }

    public function url(): string
    {
        return route('agenda.feed', $this->token);
    }
}
