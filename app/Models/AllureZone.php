<?php

namespace App\Models;

use App\Support\Allures\Referentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Zone d'allure (#114) : code court reconnu dans les consignes (#113), plage en % de la valeur de
 * référence. Catalogue par référentiel, plages discontinues acceptées, chevauchement refusé.
 */
class AllureZone extends Model
{
    protected $fillable = ['referentiel', 'code', 'label', 'pct_min', 'pct_max', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime', 'pct_min' => 'integer', 'pct_max' => 'integer'];

    /** @param  Builder<AllureZone>  $q */
    public function scopeActive(Builder $q): void
    {
        $q->whereNull('archived_at');
    }

    /** @return Collection<int, AllureZone> zones actives d'un référentiel, de la plus lente à la plus rapide. */
    public static function activeFor(Referentiel $referentiel): Collection
    {
        return self::query()->active()->where('referentiel', $referentiel->value)->orderBy('pct_min')->get();
    }

    /**
     * Couleur d'intensité de chaque zone (token `--intensite-1` à `-8`), répartie par rang sur
     * l'échelle vert → violet : rien à saisir, et la grille d'un club garde un dégradé lisible.
     *
     * @param  Collection<int, AllureZone>  $zones  triées de la plus lente à la plus rapide
     * @return array<int, string> [id => 'var(--intensite-n)']
     */
    public static function intensites(Collection $zones): array
    {
        $n = $zones->count();

        return $zones->values()->mapWithKeys(fn (AllureZone $z, int $i) => [
            $z->id => 'var(--intensite-'.($n > 1 ? 1 + (int) round($i * 7 / ($n - 1)) : 1).')',
        ])->all();
    }

    /**
     * Zone active qui chevauche la plage [min, max]. Des bornes qui se touchent (75–85 puis 85–90)
     * ne se chevauchent pas.
     */
    public static function overlapping(Referentiel $referentiel, int $min, int $max, ?int $exceptId = null): ?self
    {
        return self::query()->active()
            ->where('referentiel', $referentiel->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('pct_min', '<', $max)
            ->where('pct_max', '>', $min)
            ->first();
    }

    public function range(): string
    {
        return $this->pct_min.'–'.$this->pct_max.' %';
    }
}
