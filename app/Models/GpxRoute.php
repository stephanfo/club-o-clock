<?php

namespace App\Models;

use Database\Factories\GpxRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Parcours GPX réutilisable (PRD §4.20, §5.1). Objet de première classe : créable sans séance,
// référencé par N sessions. Nommé GpxRoute (pas Route) pour éviter la collision avec la facade
// Illuminate\Support\Facades\Route. Soft-delete coach/admin (archived_at), suppression admin seul.
class GpxRoute extends Model
{
    /** @use HasFactory<GpxRouteFactory> */
    use HasFactory;

    /** Secteurs cardinaux en français (O, pas W). Ordre = index du cap : Math.round(deg/45) % 8. */
    public const SECTORS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

    /**
     * Seuil d'allongement au-delà duquel un circuit est dit « étiré » plutôt qu'« arrondi ».
     * Calibré sur le corpus du club (arrondis ~1,0-1,4 · étirés ~1,5-2,8) : les deux familles se
     * chevauchent, aucun seuil ne les sépare parfaitement — c'est une aide au tri, pas une vérité.
     */
    public const ELONGATION_THRESHOLD = 1.45;

    /**
     * Seuils de relief, en mètres de D+ par kilomètre (le ratio usuel du cyclisme).
     *
     * ATTENTION : ces seuils sont RELATIFS au terrain du club, pas absolus. Le corpus va de 3,9 à
     * 9,1 m/km — un barème standard (vallonné à partir de ~10, montagne au-delà de ~20) classerait
     * tout en « facile » et ne trierait rien. Calibrés sur les quartiles observés (q25 6,3 · médiane
     * 6,9 · q75 7,3) pour obtenir trois groupes réellement peuplés.
     *
     * Conséquence assumée : « Exigeant » veut dire « exigeant POUR NOS SORTIES ». Si le club se met
     * un jour à rouler en montagne, ces seuils sont à revoir — d'où l'affichage systématique de la
     * valeur brute à côté du libellé, qui, elle, ne ment jamais.
     */
    public const GRADE_ROLLING_MAX = 6.3;

    public const GRADE_HILLY_MAX = 7.3;

    protected $fillable = [
        'name', 'description', 'discipline_id',
        'gpx_path', 'gpx_hash', 'gpx_original_name', 'gpx_size_ko',
        'distance_km', 'dplus_m', 'dmoins_m', 'alt_min_m', 'alt_max_m', 'point_count', 'duration_min',
        'start_lat', 'start_lng', 'end_lat', 'end_lng', 'is_loop', 'elongation',
        'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng',
        'bearing_deg', 'sector', 'polyline', 'elevation_profile',
        'openrunner_embed_url', 'openrunner_public_url',
        'start_location_id', 'created_by', 'archived_at', 'archived_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'distance_km' => 'decimal:1',
        'dplus_m' => 'integer',
        'dmoins_m' => 'integer',
        'alt_min_m' => 'integer',
        'alt_max_m' => 'integer',
        'point_count' => 'integer',
        'duration_min' => 'integer',
        'start_lat' => 'decimal:7',
        'start_lng' => 'decimal:7',
        'end_lat' => 'decimal:7',
        'end_lng' => 'decimal:7',
        'is_loop' => 'boolean',
        'elongation' => 'decimal:2',
        'bbox_min_lat' => 'decimal:7',
        'bbox_min_lng' => 'decimal:7',
        'bbox_max_lat' => 'decimal:7',
        'bbox_max_lng' => 'decimal:7',
        'bearing_deg' => 'integer',
        'polyline' => 'array',
        'elevation_profile' => 'array',
        'archived_at' => 'datetime',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Parcours visibles dans la bibliothèque (non archivés). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Forme du circuit : « arrondi » ou « étiré », null si l'allongement n'a pas été extrait. */
    public function shapeLabel(): ?string
    {
        if ($this->elongation === null) {
            return null;
        }

        return (float) $this->elongation >= self::ELONGATION_THRESHOLD ? 'étiré' : 'arrondi';
    }

    /**
     * Filtre par forme (chips « Arrondi » / « Étiré » de la bibliothèque, J10.B).
     *
     * Multi-sélection depuis 2026-08-02 : plusieurs valeurs s'unissent (OU). Cocher les deux revient
     * donc à ne rien filtrer — c'est la sémantique attendue, pas un bug.
     *
     * @param  list<string>|string|null  $shape
     */
    public function scopeShape(Builder $query, array|string|null $shape): Builder
    {
        // La table sert À LA FOIS de liste blanche et d'implémentation : impossible qu'une valeur
        // acceptée en entrée n'ait pas de traduction SQL (ce qu'un match sans défaut ferait exploser).
        return self::filterByUnion($query, $shape, [
            'round' => fn (Builder $q) => $q->whereNotNull('elongation')->where('elongation', '<', self::ELONGATION_THRESHOLD),
            'long' => fn (Builder $q) => $q->where('elongation', '>=', self::ELONGATION_THRESHOLD),
        ]);
    }

    /**
     * Applique l'union (OU) des contraintes désignées par $values, en ignorant toute clé inconnue.
     *
     * Les valeurs viennent de l'URL (`#[Url]` sur la bibliothèque) : elles sont forgeables. La table
     * `$cases` fait office de liste blanche — une clé absente est silencieusement écartée, et une
     * sélection entièrement invalide laisse la requête intacte plutôt que de ne rien renvoyer.
     *
     * @param  list<string>|string|null  $values
     * @param  array<string, \Closure(Builder): Builder>  $cases
     */
    private static function filterByUnion(Builder $query, array|string|null $values, array $cases): Builder
    {
        $selected = array_values(array_intersect((array) ($values ?? []), array_keys($cases)));

        if ($selected === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($selected, $cases) {
            foreach ($selected as $value) {
                $q->orWhere($cases[$value]);
            }
        });
    }

    /**
     * Relief : mètres de dénivelé positif par kilomètre. Calculé à la volée — la donnée source est
     * déjà en base, et le stocker imposerait de tout recalculer à chaque ajustement des seuils.
     */
    public function gradeIndex(): ?float
    {
        $km = (float) $this->distance_km;

        if ($this->dplus_m === null || $km <= 0) {
            return null;
        }

        return round($this->dplus_m / $km, 1);
    }

    /** Libellé de relief : « Roulant », « Vallonné » ou « Exigeant ». Null si D+ ou distance manque. */
    public function gradeLabel(): ?string
    {
        $index = $this->gradeIndex();

        return match (true) {
            $index === null => null,
            $index < self::GRADE_ROLLING_MAX => 'Roulant',
            $index < self::GRADE_HILLY_MAX => 'Vallonné',
            default => 'Exigeant',
        };
    }

    /**
     * Filtre par relief (chips de la bibliothèque, J10.B).
     *
     * Le ratio n'étant pas stocké, la comparaison se fait en SQL sur l'expression. `distance_km > 0`
     * garde la division et écarte au passage les parcours sans métriques exploitables.
     */
    public function scopeGrade(Builder $query, array|string|null $grade): Builder
    {
        // ROUND(…, 1) et non le ratio brut : le libellé PHP classe sur la valeur AFFICHÉE (arrondie).
        // Sans cet arrondi, une trace à 7,2503 s'affiche « Exigeant · 7,3 » mais sort du filtre
        // « Exigeant » — 4 des 18 traces du corpus étaient dans ce cas. La valeur montrée fait foi.
        $ratio = 'ROUND(dplus_m / distance_km, 1)';

        $cases = [
            'rolling' => fn (Builder $q) => $q->whereRaw("$ratio < ?", [self::GRADE_ROLLING_MAX]),
            'hilly' => fn (Builder $q) => $q->whereRaw("$ratio >= ?", [self::GRADE_ROLLING_MAX])
                ->whereRaw("$ratio < ?", [self::GRADE_HILLY_MAX]),
            'tough' => fn (Builder $q) => $q->whereRaw("$ratio >= ?", [self::GRADE_HILLY_MAX]),
        ];

        if (array_intersect((array) ($grade ?? []), array_keys($cases)) === []) {
            return $query;
        }

        // Le prérequis « métriques exploitables » vaut pour toutes les valeurs : il reste EN DEHORS
        // du OU, sinon un parcours sans D+ ressortirait dès qu'une seule valeur est cochée.
        return self::filterByUnion(
            $query->whereNotNull('dplus_m')->where('distance_km', '>', 0),
            $grade,
            $cases,
        );
    }

    /**
     * Intersection avec une zone rectangulaire (§5) : quatre comparaisons indexables, aucune fonction
     * SQL ni extension spatiale (portabilité MariaDB dev / MySQL 8.4 prod).
     *
     * Une bbox n'est pas un tracé : un aller-retour diagonal a une emprise couvrant des zones où il ne
     * passe jamais → faux positifs possibles, aucun faux négatif (complet mais imprécis).
     * Bornes malformées → filtre ignoré plutôt que résultat aberrant.
     */
    public function scopeInBbox(Builder $query, ?float $minLat, ?float $minLng, ?float $maxLat, ?float $maxLng): Builder
    {
        if ($minLat === null || $minLng === null || $maxLat === null || $maxLng === null) {
            return $query;
        }
        if ($minLat > $maxLat || $minLng > $maxLng) {
            return $query;
        }

        return $query->whereNotNull('bbox_min_lat')
            ->where('bbox_min_lat', '<=', $maxLat)->where('bbox_max_lat', '>=', $minLat)
            ->where('bbox_min_lng', '<=', $maxLng)->where('bbox_max_lng', '>=', $minLng);
    }

    /** @return BelongsTo<Discipline, $this> */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    /**
     * Séances utilisant ce parcours. Alimente « séances où ce parcours a été utilisé » (fiche) ET
     * la garde de GpxRouteService::delete() : on refuse de supprimer un parcours référencé, sinon
     * nullOnDelete le viderait silencieusement de N fiches séance.
     *
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'route_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
