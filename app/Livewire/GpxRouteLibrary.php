<?php

namespace App\Livewire;

use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bibliothèque de parcours (PRD §4.20, J10.B).
 *
 * Aucun écran de référence dans design/ : screen-parcours.jsx ne couvre que le bloc de la fiche
 * séance (porté en J10.A). Structure dérivée d'Admin\MemberList — même patron de liste filtrable
 * (#[Url] par filtre, perPage + loadMore, remise à zéro de la fenêtre sur changement de filtre) —
 * et classes du design reprises telles quelles : .input pour la recherche, .chip/.is-active pour
 * les filtres, .scard pour les cartes. Seule .route-grid est nouvelle (le CSS ne couvre pas ce cas).
 *
 * Consultation ouverte à tous les membres (GpxRoutePolicy::viewAny) : la création reste coach+admin.
 */
#[Layout('layouts.app')]
#[Title('Parcours')]
class GpxRouteLibrary extends Component
{
    public const PER_PAGE = 24;

    #[Url]
    public string $search = '';

    /**
     * Filtres à valeurs multiples (2026-08-02) — au sein d'un filtre les valeurs s'UNISSENT (OU),
     * entre filtres elles se CROISENT (ET) : « secteur N ou NE, et relief exigeant ».
     *
     * Tableau vide = filtre inactif. Les valeurs sont des chaînes même pour la discipline : un
     * paramètre d'URL est du texte, et Livewire ne recaste pas les éléments d'un array.
     *
     * @var list<string> secteurs cardinaux (N|NE|E|SE|S|SO|O|NO)
     */
    #[Url]
    public array $sector = [];

    /** @var list<string> ids de discipline */
    #[Url]
    public array $discipline = [];

    /** @var list<string> round|long (GpxRoute::scopeShape) */
    #[Url]
    public array $shape = [];

    /** @var list<string> rolling|hilly|tough (GpxRoute::scopeGrade) */
    #[Url]
    public array $grade = [];

    /** @var list<string> clés de DISTANCE_BANDS */
    #[Url]
    public array $distance = [];

    /** @var list<string> ids de lieu de départ (start_location_id, #130) */
    #[Url]
    public array $location = [];

    /**
     * Période d'utilisation (#130) — sélection UNIQUE, contrairement aux chips ci-dessus : les
     * périodes s'emboîtent (ce mois-ci ⊂ 3 derniers mois ⊂ saison), les unir n'aurait aucun sens.
     * Clé de USED_PERIODS ou '' (inactif). Une clé inconnue venue de l'URL est ignorée.
     */
    #[Url]
    public string $used = '';

    /** Bornes de la période libre (`used` = custom), au format Y-m-d, heure club. Vides = ouvertes. */
    #[Url]
    public string $usedFrom = '';

    #[Url]
    public string $usedTo = '';

    /** Clé de SORTS. Normalisée au montage et à chaque changement : la valeur vient de l'URL. */
    #[Url]
    public string $sort = 'name';

    /** Inclure les parcours archivés — réservé aux coachs/admins, ignoré sinon. */
    #[Url]
    public bool $archived = false;

    /**
     * Mode d'affichage : `list` (défaut) ou `map` (J10.C bis).
     *
     * Dans l'URL pour que « voir la carte » soit partageable et survive un retour arrière. Valeur
     * forgeable comme tout `#[Url]` → normalisée par setMode(), jamais lue telle quelle.
     */
    #[Url]
    public string $mode = 'list';

    public int $perPage = self::PER_PAGE;

    /**
     * Tranches de distance : clé d'URL => [libellé, min, max|null].
     *
     * Par dizaines de km à partir de 40 (demande 2026-08-02) : le corpus du club s'étale de 49 à
     * 85 km, des tranches plus larges ne trieraient presque rien. Bornes SEMI-OUVERTES [min, max[
     * pour qu'un parcours de 50,0 km tombe dans « 50-60 » et nulle part ailleurs.
     */
    public const DISTANCE_BANDS = [
        '0-40' => ['< 40 km', 0, 40],
        '40-50' => ['40-50', 40, 50],
        '50-60' => ['50-60', 50, 60],
        '60-70' => ['60-70', 60, 70],
        '70-80' => ['70-80', 70, 80],
        '80-90' => ['80-90', 80, 90],
        '90-100' => ['90-100', 90, 100],
        '100+' => ['> 100 km', 100, null],
    ];

    /**
     * Périodes d'utilisation : clé d'URL => libellé de chip. « Utilisé » = au moins une séance TENUE
     * (commencée, non annulée — Session::scopeHeld) dans la période ; une séance seulement planifiée
     * ne compte pas, d'où « jamais utilisé » pour un parcours prévu samedi prochain.
     */
    public const USED_PERIODS = [
        'month' => 'Ce mois-ci',
        '3months' => '3 derniers mois',
        'season' => 'Cette saison',
        'custom' => 'Période…',
        'never' => 'Jamais utilisé',
    ];

    /**
     * Tris proposés : clé d'URL => libellé. Le sens est porté par la clé (un seul sélecteur, pas un
     * couple tri + sens), parce que pour la plupart des critères un seul sens a un usage réel.
     * Le relief trie sur le D+ PAR KILOMÈTRE (GpxRoute::GRADE_INDEX_SQL) et non sur le D+ total, qui
     * suit surtout la distance (#130).
     */
    public const SORTS = [
        'name' => 'Nom A → Z',
        'distance' => 'Distance croissante',
        'distance-desc' => 'Distance décroissante',
        'grade' => 'Relief : du plus roulant',
        'grade-desc' => 'Relief : du plus exigeant',
        'recent' => 'Ajoutés récemment',
        'last-used' => 'Utilisés récemment',
        'most-used' => 'Les plus utilisés',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', GpxRoute::class);
        $this->normalizeSort();
    }

    public function updated(string $name): void
    {
        // Tout changement de filtre/recherche réinitialise la fenêtre de pagination.
        if (in_array($name, ['search', 'sector', 'discipline', 'shape', 'grade', 'distance', 'location', 'usedFrom', 'usedTo', 'archived'], true)) {
            $this->perPage = self::PER_PAGE;
            $this->notifyMap();
        }
    }

    /**
     * Le tri ne change pas le JEU affiché, seulement son ordre : pas de notifyMap(). La fenêtre est
     * tout de même ramenée au début, sinon « charger plus » aurait étendu un autre classement.
     */
    public function updatedSort(): void
    {
        $this->normalizeSort();
        $this->perPage = self::PER_PAGE;
    }

    private function normalizeSort(): void
    {
        if (! array_key_exists($this->sort, self::SORTS)) {
            $this->sort = 'name';
        }
    }

    /**
     * Chip de période : sélection unique, un second clic sur la chip active la désactive. Quitter
     * « Période… » efface ses bornes, pour qu'elles ne ressurgissent pas, invisibles, dans l'URL.
     */
    public function setUsed(string $period): void
    {
        if (! array_key_exists($period, self::USED_PERIODS)) {
            return;
        }

        $this->used = $this->used === $period ? '' : $period;
        if ($this->used !== 'custom') {
            $this->usedFrom = '';
            $this->usedTo = '';
        }

        $this->perPage = self::PER_PAGE;
        $this->notifyMap();
    }

    /**
     * Prévient la carte que le jeu filtré a changé.
     *
     * L'îlot Leaflet est en `wire:ignore` : ses attributs ne sont jamais re-rendus, donc aucune
     * interpolation Blade (`x-effect="load('…')"`) ne peut lui transmettre une nouvelle URL — elle
     * resterait figée sur celle du montage. Un événement, lui, ne passe pas par le DOM.
     *
     * Émis même en mode liste : l'utilisateur peut filtrer puis basculer sur la carte, et l'îlot
     * n'existe pas encore à ce moment — le `url:` de son x-data porte alors déjà les bons filtres.
     */
    private function notifyMap(): void
    {
        $this->dispatch('gpx-routes-filtered', url: $this->tracesUrl());
    }

    /** Filtres à valeurs multiples, seuls acceptés par toggle() / isOn(). */
    private const MULTI = ['sector', 'discipline', 'shape', 'grade', 'distance', 'location'];

    /**
     * Bascule d'une chip : la valeur s'ajoute au filtre, un second clic la retire (pas de chip
     * « Tous » à maintenir). Vider la liste rend le filtre inactif.
     */
    public function toggle(string $filter, string $value): void
    {
        if (! in_array($filter, self::MULTI, true)) {
            return;
        }

        $current = $this->{$filter};
        $this->{$filter} = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];

        $this->perPage = self::PER_PAGE;
        // Les chips sont des ACTIONS, pas des wire:model : updated() ne se déclenche pas ici.
        $this->notifyMap();
    }

    /**
     * Bascule liste / carte. Toute valeur inconnue retombe sur `list` : le paramètre vient de l'URL,
     * et un mode invalide ne doit pas produire un écran vide.
     */
    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'map' ? 'map' : 'list';
    }

    public function isMap(): bool
    {
        return $this->mode === 'map';
    }

    /** État d'une chip — évite de répéter la logique in_array dans la vue. */
    public function isOn(string $filter, string $value): bool
    {
        return in_array($filter, self::MULTI, true) && in_array($value, $this->{$filter}, true);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'sector', 'discipline', 'shape', 'grade', 'distance', 'location', 'used', 'usedFrom', 'usedTo', 'archived']);
        $this->perPage = self::PER_PAGE;
        // Ni `mode` ni `sort` ne sont réinitialisés : ce sont des manières de LIRE le jeu, pas des
        // filtres — réinitialiser les filtres ne doit ni éjecter de la carte ni défaire un classement.
        $this->notifyMap();
    }

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->activeFilterCount() > 0;
    }

    /**
     * Nombre de BLOCS de filtres actifs, affiché sur le bouton « Filtres (n) » du repli mobile.
     * La recherche n'y figure pas : elle reste visible, repliée ou non.
     */
    public function activeFilterCount(): int
    {
        return count(array_filter([
            $this->sector !== [], $this->discipline !== [], $this->distance !== [],
            $this->shape !== [], $this->grade !== [], $this->location !== [],
            $this->usedFilters(),
            $this->archived && $this->canManage(),
        ]));
    }

    /** @return Builder<GpxRoute> */
    private function baseQuery(): Builder
    {
        return GpxRoute::query()
            // Les archivés ne sortent que sur demande explicite ET si l'utilisateur peut les gérer.
            ->when(! ($this->archived && $this->canManage()), fn (Builder $q) => $q->active())
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.$this->search.'%';
                $q->where(fn (Builder $sub) => $sub->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($this->sector !== [], fn (Builder $q) => $q->whereIn('sector', $this->sector))
            ->when($this->discipline !== [], fn (Builder $q) => $q->whereIn('discipline_id', $this->discipline))
            ->when($this->location !== [], fn (Builder $q) => $q->whereIn('start_location_id', $this->location))
            ->shape($this->shape)
            ->grade($this->grade)
            ->tap(fn (Builder $q) => $this->applyDistance($q))
            ->tap(fn (Builder $q) => $this->applyUsed($q));
    }

    /**
     * Filtre « Utilisé » : période → bornes en heure club, puis GpxRoute::scopeUsedBetween.
     *
     * @param  Builder<GpxRoute>  $query
     */
    private function applyUsed(Builder $query): void
    {
        if ($this->used === 'never') {
            $query->neverUsed();
        } elseif (($range = $this->usedRange()) !== null) {
            $query->usedBetween(...$range);
        }
    }

    /**
     * Bornes de la période « Utilisé » active, en heure club ; null si aucune période ne filtre
     * (pas de choix, « Jamais utilisé », ou période personnalisée encore sans date — choisir
     * « Période… » ne doit rien masquer tant qu'aucune date n'est posée).
     *
     * Les périodes glissantes partent du début de jour (heure club) : « les 3 derniers mois » d'un
     * 7 octobre à 15 h commencent le 7 juillet à 0 h, pas à 15 h.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}|null
     */
    private function usedRange(): ?array
    {
        $tz = ClubSettings::current()->timezone ?: 'Europe/Paris';
        $now = Carbon::now($tz);

        $range = match ($this->used) {
            'month' => [$now->copy()->startOfMonth(), null],
            '3months' => [$now->copy()->subMonthsNoOverflow(3)->startOfDay(), null],
            'season' => [ClubSettings::current()->seasonStart($now), null],
            'custom' => $this->customBounds($tz),
            default => null,
        };

        return $range === [null, null] ? null : $range;
    }

    /** Le filtre « Utilisé » restreint-il vraiment la liste ? (compteur « Filtres (n) ») */
    private function usedFilters(): bool
    {
        return $this->used === 'never' || $this->usedRange() !== null;
    }

    /**
     * Bornes de la période personnalisée en heure club, jour entier inclus. Des bornes inversées
     * (saisie au clavier, URL forgée : min/max HTML ne retiennent ni l'un ni l'autre) sont
     * permutées plutôt que de rendre une liste vide sans explication.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function customBounds(string $tz): array
    {
        $from = self::parseDay($this->usedFrom, $tz);
        $to = self::parseDay($this->usedTo, $tz);

        if ($from !== null && $to !== null && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from?->startOfDay(), $to?->endOfDay()];
    }

    /**
     * Date Y-m-d venue de l'URL → Carbon en heure club, ou null si absente ou invalide. Une borne
     * forgée est ignorée (la période reste ouverte de ce côté) plutôt que de faire échouer l'écran.
     */
    private static function parseDay(string $value, string $tz): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = Carbon::createFromFormat('!Y-m-d', $value, $tz);

        // createFromFormat déborde sans broncher (2026-02-31 → 3 mars) : on refuse ce qui a bougé.
        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * Tri de la liste (#130). Toujours départagé par le nom puis l'id, pour un ordre stable d'une
     * page de « charger plus » à l'autre.
     *
     * Les parcours SANS la donnée triée (pas de distance, relief incalculable, jamais utilisés) vont
     * en fin de liste quel que soit le sens : en tête d'un tri croissant, ils masqueraient
     * précisément ce qu'on cherche. Le `IS NULL` en premier critère s'en charge — MySQL/MariaDB
     * placent sinon les NULL en tête d'un tri croissant.
     */
    private function applySort(Builder $query): void
    {
        $grade = GpxRoute::GRADE_INDEX_SQL;

        match ($this->sort) {
            'distance' => $query->orderByRaw('distance_km IS NULL')->orderBy('distance_km'),
            'distance-desc' => $query->orderByRaw('distance_km IS NULL')->orderByDesc('distance_km'),
            'grade' => $query->orderByRaw("($grade) IS NULL")->orderByRaw("$grade ASC"),
            'grade-desc' => $query->orderByRaw("($grade) IS NULL")->orderByRaw("$grade DESC"),
            'recent' => $query->orderByDesc('created_at'),
            // Alias des sous-requêtes posées par withUsage() ; un tri décroissant met déjà les NULL
            // (jamais utilisé) en fin de liste.
            'last-used' => $query->orderByDesc('last_used_at'),
            'most-used' => $query->orderByDesc('uses_count'),
            default => null,
        };

        $query->orderBy('name')->orderBy('id');
    }

    /**
     * Dernière utilisation ou nombre d'utilisations (séances tenues), en sous-requête corrélée
     * sur `sessions.route_id` (indexé) : sert au tri ET à l'affichage sur la carte. Posée
     * seulement pour le tri qui la lit — sinon chaque rendu (frappe de recherche, mode carte)
     * paierait deux sous-requêtes par ligne pour rien.
     *
     * Comptée sur la période « Utilisé » active : « Cette saison » + « Les plus utilisés » classe
     * les parcours de la saison et affiche « 2 séances », pas leur total historique.
     */
    private function withUsage(Builder $query): void
    {
        [$from, $to] = $this->usedRange() ?? [null, null];
        $held = fn ($s) => $s->heldBetween($from, $to);

        match ($this->sort) {
            'last-used' => $query->withMax(['sessions as last_used_at' => $held], 'start_at'),
            'most-used' => $query->withCount(['sessions as uses_count' => $held]),
            default => null,
        };
    }

    /**
     * Tranches de distance cochées → union d'intervalles.
     *
     * Les tranches contiguës sont FUSIONNÉES avant traduction en SQL : cocher 50-60, 60-70 et 70-80
     * produit `>= 50 AND < 80`, pas trois `OR` équivalents. C'est le cas d'usage dominant (on coche
     * des tranches voisines pour exprimer une plage), et une chaîne de OR sur une colonne indexée
     * dégrade le plan d'exécution sans rien apporter.
     */
    private function applyDistance(Builder $query): void
    {
        $bands = array_values(array_filter(
            self::DISTANCE_BANDS,
            fn (string $key) => in_array($key, $this->distance, true),
            ARRAY_FILTER_USE_KEY,
        ));

        if ($bands === []) {
            return;
        }

        // DISTANCE_BANDS est déjà ordonnée par borne inférieure croissante : une seule passe suffit.
        $ranges = [];
        foreach ($bands as [, $min, $max]) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $min) {
                $ranges[$last][1] = $max;   // contiguë : on étend, `null` (dernière tranche) inclus
            } else {
                $ranges[] = [$min, $max];
            }
        }

        $query->where(function (Builder $q) use ($ranges) {
            foreach ($ranges as [$min, $max]) {
                $q->orWhere(function (Builder $sub) use ($min, $max) {
                    $sub->where('distance_km', '>=', $min);
                    if ($max !== null) {
                        $sub->where('distance_km', '<', $max);
                    }
                });
            }
        });
    }

    /**
     * Même jeu filtré que la liste, restreint aux parcours dessinables (J10.C bis).
     *
     * Public car GpxRouteTracesController s'en sert pour servir la carte : les filtres ne sont ainsi
     * écrits qu'une fois, et la carte ne peut pas diverger de la liste. Le tri par nom est conservé
     * pour que la troncature à MAX_TRACES soit déterministe.
     *
     * @return Builder<GpxRoute>
     */
    public function tracesQuery(): Builder
    {
        // Un parcours sans bloc géo n'a pas de polyline : il figure dans la liste (ses métriques
        // restent utiles) mais n'a rien à dessiner. On l'écarte ici seulement.
        return $this->baseQuery()->whereNotNull('polyline')->orderBy('name');
    }

    /** URL de l'endpoint des tracés, filtres courants inclus (consommée par l'îlot Alpine). */
    private function tracesUrl(): string
    {
        return route('gpx-routes.traces', array_filter([
            'search' => $this->search,
            'sector' => $this->sector,
            'discipline' => $this->discipline,
            'shape' => $this->shape,
            'grade' => $this->grade,
            'distance' => $this->distance,
            'location' => $this->location,
            'used' => $this->used,
            'usedFrom' => $this->used === 'custom' ? $this->usedFrom : '',
            'usedTo' => $this->used === 'custom' ? $this->usedTo : '',
            'archived' => $this->archived ? 1 : null,
        ]));
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('create', GpxRoute::class) ?? false;
    }

    /**
     * Lieux proposés par le filtre « Départ » : ceux d'au moins un parcours VISIBLE (mêmes règles
     * d'archivage que la liste), pas toute la table — une chip qui ne peut rien renvoyer est du bruit.
     * Un lieu déjà coché reste proposé même s'il ne l'est plus, pour qu'on puisse le décocher.
     *
     * @return Collection<int, Location>
     */
    private function startLocations(): Collection
    {
        $routes = GpxRoute::query()
            ->when(! ($this->archived && $this->canManage()), fn (Builder $q) => $q->active())
            ->whereNotNull('start_location_id')
            ->select('start_location_id');

        return Location::query()
            ->where(fn (Builder $q) => $q->whereIn('id', $routes)->orWhereIn('id', $this->location))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Date de dernière utilisation pour une carte : année ajoutée seulement si elle diffère. */
    public static function usedOnLabel(?string $startAt): string
    {
        if ($startAt === null) {
            return 'jamais utilisé';
        }

        $tz = ClubSettings::current()->timezone ?: 'Europe/Paris';
        $day = Carbon::parse($startAt, 'UTC')->setTimezone($tz);

        return 'utilisé le '.$day->isoFormat($day->year === Carbon::now($tz)->year ? 'ddd D MMM' : 'D MMM YYYY');
    }

    public function render()
    {
        $query = $this->baseQuery()
            // preventLazyLoading est actif hors prod : la vue lit la discipline de chaque carte.
            ->with('discipline')
            ->tap(fn (Builder $q) => $this->withUsage($q))
            ->tap(fn (Builder $q) => $this->applySort($q));

        $total = (clone $query)->toBase()->getCountForPagination();

        return view('livewire.gpx-route-library', [
            'routes' => $query->take($this->perPage)->get(),
            'total' => $total,
            'disciplines' => Discipline::orderBy('label')->get(),
            'locations' => $this->startLocations(),
            'canManage' => $this->canManage(),
            // URL de DÉPART de l'îlot seulement (son x-data au montage). Les changements ultérieurs
            // ne passent PAS par ici : l'îlot est en wire:ignore, ses attributs ne sont jamais
            // re-rendus — c'est notifyMap() qui l'informe, par événement. Aucun tracé ne transite
            // par le state du composant, uniquement cette URL.
            'tracesUrl' => $this->tracesUrl(),
            // Les parcours sans géo ne sont pas dessinables : on l'annonce plutôt que de laisser
            // croire à une carte exhaustive (le compte de la liste ne correspondrait pas).
            'notMappable' => $this->isMap()
                ? (clone $query)->whereNull('polyline')->toBase()->getCountForPagination()
                : 0,
        ]);
    }
}
