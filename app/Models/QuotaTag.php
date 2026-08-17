<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Catalogue Tag de quota (PRD §5.1, §4.10).
class QuotaTag extends Model
{
    protected $fillable = ['code', 'label', 'max_per_week', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = [
        'max_per_week' => 'integer',
        'archived_at' => 'datetime',
    ];
}
