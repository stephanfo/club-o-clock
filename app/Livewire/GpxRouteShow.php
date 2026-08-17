<?php

namespace App\Livewire;

use App\Models\ClubSettings;
use App\Models\GpxRoute;
use App\Models\Session;
use App\Services\GpxRouteService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Fiche d'un parcours de la bibliothèque (PRD §4.20, J10.C).
 *
 * Consultation ouverte à tous les membres (GpxRoutePolicy::view) ; l'archivage est coach+admin et
 * la suppression définitive admin seul. Pas de composant partagé avec le bloc Parcours de la fiche
 * séance (doc J10 §4 bis) : les deux contextes divergent trop, la duplication du bloc métriques est
 * assumée.
 */
#[Layout('layouts.app')]
#[Title('Parcours')]
class GpxRouteShow extends Component
{
    public GpxRoute $gpxRoute;

    /** Confirmation de suppression définitive (<x-dialog danger>). */
    public bool $confirmingDelete = false;

    /** Confirmation d'archivage — le geste retire le parcours de la bibliothèque pour tout le club. */
    public bool $confirmingArchive = false;

    public function mount(GpxRoute $gpxRoute): void
    {
        $this->authorize('view', $gpxRoute);
        $this->gpxRoute = $gpxRoute;
    }

    /**
     * Séances utilisant ce parcours, la plus récente d'abord.
     *
     * Bornée à 20 : un parcours de club roulé chaque dimanche en accumule des dizaines, et la fiche
     * n'est pas un agenda. Le compte total reste affiché à côté.
     *
     * @return Collection<int, Session>
     */
    public function linkedSessions(): Collection
    {
        return $this->gpxRoute->sessions()
            // preventLazyLoading est actif hors prod, et <x-session-card> lit tout ça.
            ->with(['discipline', 'quotaTag', 'location', 'registrations', 'activeAperoFlags'])
            ->orderByDesc('start_at')
            ->take(20)
            ->get();
    }

    public function archive(): void
    {
        $this->authorize('archive', $this->gpxRoute);

        app(GpxRouteService::class)->archive($this->gpxRoute, auth()->user());

        $this->confirmingArchive = false;
        $this->gpxRoute->refresh();
        session()->flash('status', 'Parcours archivé. Il reste consultable par l\'encadrement et peut être restauré.');
    }

    public function restore(): void
    {
        $this->authorize('archive', $this->gpxRoute);

        app(GpxRouteService::class)->restore($this->gpxRoute, auth()->user());

        $this->gpxRoute->refresh();
        session()->flash('status', 'Parcours restauré : il réapparaît dans la bibliothèque.');
    }

    /**
     * Suppression définitive. Le service refuse si des séances référencent le parcours — l'UI ne
     * propose alors même pas le bouton, mais la garde reste ici : une action Livewire est appelable
     * directement, l'absence de bouton n'est pas une protection.
     */
    public function delete()
    {
        $this->authorize('delete', $this->gpxRoute);

        try {
            app(GpxRouteService::class)->delete($this->gpxRoute, auth()->user());
        } catch (RuntimeException $e) {
            $this->confirmingDelete = false;
            session()->flash('warn', $e->getMessage());

            return null;
        }

        session()->flash('status', 'Parcours supprimé.');

        return $this->redirect(route('gpx-routes.index'), navigate: true);
    }

    public function render()
    {
        // loadMissing DANS render() et non mount() : la ré-hydratation Livewire perd les relations
        // imbriquées (mémoire projet).
        $this->gpxRoute->loadMissing(['discipline', 'startLocation', 'creator']);

        $user = auth()->user();
        $sessionCount = $this->gpxRoute->sessions()->count();

        return view('livewire.gpx-route-show', [
            'sessions' => $this->linkedSessions(),
            'sessionCount' => $sessionCount,
            // <x-session-card> affiche les horaires en heure club.
            'tz' => ClubSettings::current()->timezone,
            // sanitizeGeo() rejette le bloc géo EN BLOC : bbox nulle ⇒ ni secteur, ni forme, ni
            // polyline, ni profil. Une seule colonne suffit donc à détecter le cas.
            'missingGeo' => $this->gpxRoute->bbox_min_lat === null,
            'canManage' => $user?->can('update', $this->gpxRoute) ?? false,
            'canArchive' => $user?->can('archive', $this->gpxRoute) ?? false,
            // Bouton « Supprimer » masqué dès qu'une séance référence le parcours : le service
            // refuserait de toute façon, autant ne pas proposer une action qui n'aboutira pas.
            'canDelete' => ($user?->can('delete', $this->gpxRoute) ?? false) && $sessionCount === 0,
            'sessionCountBlocksDelete' => $sessionCount > 0,
        ]);
    }
}
