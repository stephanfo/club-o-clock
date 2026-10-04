<?php

namespace App\Models;

use App\Support\Allures\Referentiel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Zone d'allure (#114) : code court reconnu dans les consignes (#113), plage en % de la valeur de
 * référence. Catalogue par référentiel, plages discontinues acceptées, chevauchement refusé.
 * Des alias (« SV2 », « SubT »…) s'affichent à côté du code et sont reconnus comme lui.
 */
class AllureZone extends Model
{
    protected $fillable = ['referentiel', 'code', 'label', 'aliases', 'pct_min', 'pct_max', 'archived_at'];

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

    /**
     * Codes et alias des zones actives (hors `exceptId`), en minuscules, vers le code de leur zone :
     * ce qu'une autre zone ne peut plus prendre sans rendre une consigne ambiguë.
     *
     * @return \Illuminate\Support\Collection<string, string>
     */
    public static function motsPris(Referentiel $referentiel, ?int $exceptId = null): \Illuminate\Support\Collection
    {
        return self::query()->active()
            ->where('referentiel', $referentiel->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get()
            ->flatMap(fn (self $z) => collect([$z->code, ...$z->aliasList()])->mapWithKeys(fn ($c) => [mb_strtolower($c) => $z->code]));
    }

    /**
     * Alias saisis « SV2, SubT » (virgules ou espaces), sans doublon.
     *
     * @return list<string>
     */
    public static function parseAliases(?string $saisie): array
    {
        $morceaux = preg_split('/[\s,;]+/u', (string) $saisie, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($morceaux));
    }

    /** @return list<string> */
    public function aliasList(): array
    {
        return self::parseAliases($this->aliases);
    }

    /** Code suivi de ses alias : « Z3 · SV2 · SubT ». */
    public function codes(): string
    {
        return implode(' · ', [$this->code, ...$this->aliasList()]);
    }

    /**
     * Badge du curseur : code, libellé, puis alias entre parenthèses — « Z3 · Seuil (SV2, SubT) ».
     * Un alias identique au libellé n'est pas répété.
     */
    public function badge(): string
    {
        $aliases = array_values(array_filter($this->aliasList(), fn ($a) => mb_strtolower($a) !== mb_strtolower($this->label)));

        return $this->code.' · '.$this->label.($aliases ? ' ('.implode(', ', $aliases).')' : '');
    }

    public function range(): string
    {
        return $this->pct_min.'–'.$this->pct_max.' %';
    }
}
