<?php

namespace App\Models;

use App\Support\Allures\Referentiel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valeur de référence courante d'un membre pour un référentiel (#114) — la VMA pour la course.
 * Une seule ligne par (membre, référentiel), écrasée à chaque mise à jour : pas d'historique, et
 * jamais le chrono qui a servi à l'estimer (§3.2, suivi de performance hors V1). Visible du seul
 * membre concerné.
 */
class ReferenceValue extends Model
{
    public const SOURCE_SAISIE = 'saisie';

    public const SOURCE_ESTIMATION = 'estimation';

    protected $fillable = ['user_id', 'referentiel', 'value', 'source', 'source_distance', 'measured_on'];

    /** @var array<string, string> */
    protected $casts = ['value' => 'float', 'measured_on' => 'date', 'referentiel' => Referentiel::class];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** « estimée depuis un 10 km, le 12/09 » / « saisie le 12/09 ». */
    public function originLabel(): string
    {
        $date = $this->measured_on->locale('fr')->isoFormat('D MMM YYYY');
        if ($this->source === self::SOURCE_ESTIMATION) {
            $distance = $this->referentiel->distances()[$this->source_distance][0] ?? null;

            return $distance !== null ? 'estimée depuis un '.mb_strtolower($distance).", le {$date}" : "estimée le {$date}";
        }

        return "saisie le {$date}";
    }
}
