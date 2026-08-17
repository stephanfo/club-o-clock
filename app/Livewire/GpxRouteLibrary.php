<?php

namespace App\Livewire;

use App\Models\Discipline;
use App\Models\GpxRoute;
use Illuminate\Database\Eloquent\Builder;
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

    public function mount(): void
    {
        $this->authorize('viewAny', GpxRoute::class);
    }

    public function updated(string $name): void
    {
        // Tout changement de filtre/recherche réinitialise la fenêtre de pagination.
        if (in_array($name, ['search', 'sector', 'discipline', 'shape', 'grade', 'distance', 'archived'], true)) {
            $this->perPage = self::PER_PAGE;
            $this->notifyMap();
        }
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
    private const MULTI = ['sector', 'discipline', 'shape', 'grade', 'distance'];

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
        $this->reset(['search', 'sector', 'discipline', 'shape', 'grade', 'distance', 'archived']);
        $this->perPage = self::PER_PAGE;
        // `mode` n'est PAS réinitialisé : réinitialiser les filtres ne doit pas éjecter de la carte.
        $this->notifyMap();
    }

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->archived
            || $this->sector !== [] || $this->discipline !== []
            || $this->shape !== [] || $this->grade !== [] || $this->distance !== [];
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
            ->shape($this->shape)
            ->grade($this->grade)
            ->tap(fn (Builder $q) => $this->applyDistance($q));
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
            'archived' => $this->archived ? 1 : null,
        ]));
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('create', GpxRoute::class) ?? false;
    }

    public function render()
    {
        $query = $this->baseQuery()
            // preventLazyLoading est actif hors prod : la vue lit la discipline de chaque carte.
            ->with('discipline')
            ->orderBy('name');

        $total = (clone $query)->toBase()->getCountForPagination();

        return view('livewire.gpx-route-library', [
            'routes' => $query->take($this->perPage)->get(),
            'total' => $total,
            'disciplines' => Discipline::orderBy('label')->get(),
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
