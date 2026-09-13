<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

// WeatherCacheEntry (PRD §5.1, §4.13.5). Cache Open-Meteo 3h par (lieu, créneau).
class WeatherCacheEntry extends Model
{
    use Prunable;

    protected $fillable = ['latitude', 'longitude', 'slot', 'forecast', 'fetched_at'];

    /**
     * Élagage (model:prune, planifié) : les créneaux passés, que plus personne ne relit —
     * `SessionShow::weatherData()` rend `none` dès `hasStarted()` et `forecast()` rend `null` hors
     * fenêtre. Sans ça la table ne rétrécissait jamais, d'une croissance lente mais non bornée.
     *
     * Le critère est `slot`, **pas `fetched_at`** : une entrée périmée pour le TTL de 3 h mais dont
     * le créneau est à venir est précisément la réserve du stale-while-error (WeatherService) —
     * c'est elle qu'on sert quand Open-Meteo est injoignable.
     */
    public function prunable(): Builder
    {
        return static::query()->where('slot', '<', now());
    }

    /** @var array<string, string> */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'slot' => 'datetime',
        'forecast' => 'array',
        'fetched_at' => 'datetime',
    ];
}
