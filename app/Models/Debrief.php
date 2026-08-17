<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Debrief (PRD §5.1, §4.12.5). kind=competition en V1. Soft-delete admin (archived_at).
class Debrief extends Model
{
    protected $fillable = [
        'session_id', 'author_id', 'content_markdown', 'archived_at', 'archived_by',
    ];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime'];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Débriefs visibles dans la liste publique (non archivés). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
