<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bibliothèque de lieux (PRD §5.1, §4.13.4).
class Location extends Model
{
    protected $fillable = [
        'name', 'address', 'latitude', 'longitude', 'kind', 'notes', 'created_by', 'is_archived',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_archived' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
