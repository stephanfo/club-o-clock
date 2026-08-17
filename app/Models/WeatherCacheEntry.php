<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// WeatherCacheEntry (PRD §5.1, §4.13.5). Cache Open-Meteo 3h par (lieu, créneau).
class WeatherCacheEntry extends Model
{
    protected $fillable = ['latitude', 'longitude', 'slot', 'forecast', 'fetched_at'];

    /** @var array<string, string> */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'slot' => 'datetime',
        'forecast' => 'array',
        'fetched_at' => 'datetime',
    ];
}
