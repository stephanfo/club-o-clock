<?php

namespace App\Models;

use App\Support\Allures\Referentiel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Niveau de la table club des allures cibles (#114) : % de la valeur de référence tenable par
 * distance. `targets` = [clé distance => [min, max|null]], max null = borne ouverte (« 95+ »).
 * Table vide : le modèle de Riegel s'applique.
 */
class AllureLevel extends Model
{
    protected $fillable = ['referentiel', 'label', 'sort_order', 'targets'];

    /** @var array<string, string> */
    protected $casts = ['targets' => 'array', 'sort_order' => 'integer'];

    /** @return Collection<int, AllureLevel> */
    public static function forReferentiel(Referentiel $referentiel): Collection
    {
        return self::query()->where('referentiel', $referentiel->value)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array{float, ?float}|null bornes pour une distance, null si absente. */
    public function target(string $distance): ?array
    {
        $t = $this->targets[$distance] ?? null;
        if (! is_array($t) || ! isset($t[0])) {
            return null;
        }

        return [(float) $t[0], isset($t[1]) ? (float) $t[1] : null];
    }
}
