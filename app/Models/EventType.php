<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Catalogue Types d'épreuve (PRD §5.1, §4.6.2).
class EventType extends Model
{
    protected $fillable = ['label', 'sort_order', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime'];
}
