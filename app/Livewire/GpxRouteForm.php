<?php

namespace App\Livewire;

use App\Models\Discipline;
use App\Models\GpxRoute;
use App\Models\Location;
use App\Services\GpxRouteService;
use App\Support\GpxStats;
use App\Support\Markup;
use App\Support\OpenRunner;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

// Création / édition d'un parcours de la bibliothèque (PRD §4.20). Coach + admin (GpxRoutePolicy).
// Miroir court de SessionForm : toute l'écriture de fichier passe par GpxRouteService, jamais ici.
#[Layout('layouts.app')]
#[Title('Parcours — édition')]
class GpxRouteForm extends Component
{
    use WithFileUploads;

    public ?GpxRoute $gpxRoute = null;

    public string $name = '';

    public string $description = '';

    public ?int $discipline_id = null;

    public ?int $start_location_id = null;

    public string $openrunner_embed_url = '';

    public string $openrunner_public_url = '';

    // Fichier brut + métadonnées parsées CÔTÉ CLIENT (le serveur ne parse jamais de GPX, §7.6).
    public $gpxFile = null;

    public ?array $gpxStats = null;

    /** Parcours actif portant déjà exactement le même fichier, détecté au dépôt (§1). */
    public ?int $duplicateId = null;

    public ?string $duplicateName = null;

    /** L'utilisateur a explicitement choisi de créer malgré le doublon signalé. */
    public bool $duplicateAcknowledged = false;

    public function mount(?GpxRoute $gpxRoute = null): void
    {
        if ($gpxRoute && $gpxRoute->exists) {
            $this->authorize('update', $gpxRoute);
            $this->gpxRoute = $gpxRoute;
            $this->name = $gpxRoute->name;
            $this->description = $gpxRoute->description ?? '';
            $this->discipline_id = $gpxRoute->discipline_id;
            $this->start_location_id = $gpxRoute->start_location_id;
            $this->openrunner_embed_url = $gpxRoute->openrunner_embed_url ?? '';
            $this->openrunner_public_url = $gpxRoute->openrunner_public_url ?? '';
            $this->gpxStats = $this->statsFromRoute($gpxRoute);
        } else {
            $this->authorize('create', GpxRoute::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'discipline_id' => ['nullable', 'exists:disciplines,id'],
            'start_location_id' => ['nullable', 'exists:locations,id'],
            // Whitelist stricte côté serveur, même règle que SessionForm (§4.13.1).
            'openrunner_embed_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (filled($value) && ! OpenRunner::validEmbedUrl($value)) {
                    $fail('Lien d\'embed OpenRunner invalide — colle l\'URL `src` issue de la fonctionnalité Embed d\'OR Pro (elle contient /embed.html?code=…).');
                }
            }],
            'openrunner_public_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (filled($value) && ! OpenRunner::validPublicUrl($value)) {
                    $fail('Lien public OpenRunner invalide (https://www.openrunner.com/…).');
                }
            }],
            // Le fichier est obligatoire à la création, optionnel en édition (remplacement).
            // Règles partagées avec SessionForm : source unique.
            'gpxFile' => GpxStats::fileRules(required: $this->gpxRoute === null),
        ];
    }

    /**
     * Dès qu'un fichier est déposé, on cherche un parcours actif portant le même hash. On SIGNALE
     * (bannière + « Utiliser ce parcours » / « Créer quand même »), on ne bloque pas : la détection
     * ne couvre que les fichiers binairement identiques.
     */
    public function updatedGpxFile(): void
    {
        $this->duplicateId = null;
        $this->duplicateName = null;
        $this->duplicateAcknowledged = false;

        if (! $this->gpxFile) {
            return;
        }

        $service = app(GpxRouteService::class);
        $existing = $service->findDuplicateByHash(
            $service->hashUpload($this->gpxFile),
            $this->gpxRoute?->id,
        );

        if ($existing) {
            $this->duplicateId = $existing->id;
            $this->duplicateName = $existing->name;
        }
    }

    /** « Créer quand même » : lève le blocage de la bannière de doublon. */
    public function acknowledgeDuplicate(): void
    {
        $this->duplicateAcknowledged = true;
    }

    /**
     * ANNULE le dépôt en cours. Le composant Alpine gpxField est partagé avec SessionForm, dont le
     * removeGpx() détache un parcours de la bibliothèque ; ici il n'y a rien à détacher, seulement
     * un fichier en attente à oublier — avec la bannière de doublon qu'il avait déclenchée. Un
     * parcours déjà enregistré n'est pas touché : le retrait ramène simplement au fichier d'origine.
     */
    public function removeGpx(): void
    {
        $this->gpxFile = null;
        $this->gpxStats = $this->gpxRoute !== null ? $this->statsFromRoute($this->gpxRoute) : null;
        $this->duplicateId = null;
        $this->duplicateName = null;
        $this->duplicateAcknowledged = false;
    }

    public function save(GpxRouteService $service)
    {
        // Défense en profondeur : les actions Livewire ne repassent pas par mount().
        if ($this->gpxRoute) {
            $this->authorize('update', $this->gpxRoute);
        } else {
            $this->authorize('create', GpxRoute::class);
        }

        $edition = $this->gpxRoute !== null;
        $data = $this->validate();

        // Doublon signalé et non levé : on refuse plutôt que de créer une trace en double en silence.
        if ($this->duplicateId !== null && ! $this->duplicateAcknowledged) {
            session()->flash('warn', 'Ce GPX existe déjà dans la bibliothèque. Utilise le parcours existant, ou confirme la création.');

            return null;
        }

        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ? Markup::clean($data['description']) : null,
            'discipline_id' => $data['discipline_id'],
            'start_location_id' => $data['start_location_id'],
            'openrunner_embed_url' => $data['openrunner_embed_url'] ?: null,
            'openrunner_public_url' => $data['openrunner_public_url'] ?: null,
        ];

        if ($this->gpxRoute) {
            $this->gpxRoute->update($attributes);
            if ($this->gpxFile) {
                $service->replaceGpx($this->gpxRoute, $this->gpxFile, $this->gpxStats, auth()->user());
            }
        } else {
            $this->gpxRoute = $service->createFromUpload($this->gpxFile, $attributes, $this->gpxStats, auth()->user());
        }

        session()->flash('status', 'Parcours enregistré.');

        // En ÉDITION on reste où l'on est. Rediriger vers l'écran courant n'apportait rien et
        // empilait une entrée d'historique identique à chaque enregistrement (la redirection
        // `navigate` fait un pushState) : trois sauvegardes demandaient trois retours pour sortir,
        // et les deux premiers rendaient une version périmée du même formulaire (#26).
        if ($edition) {
            // Le dépôt en attente est consommé : sans ça, un second clic sur « Enregistrer »
            // rejouerait replaceGpx() avec le même fichier temporaire. removeGpx() remet les stats
            // affichées sur le fichier désormais enregistré.
            $this->gpxRoute->refresh();
            $this->removeGpx();

            return null;
        }

        // En création, la redirection est nécessaire : l'URL doit porter l'id du parcours créé.
        return $this->redirect(route('gpx-routes.edit', $this->gpxRoute), navigate: true);
    }

    /**
     * Métadonnées d'affichage, dans la forme attendue par la dropzone (clés client).
     *
     * @return array<string, mixed>
     */
    private function statsFromRoute(GpxRoute $route): array
    {
        return array_filter([
            'name' => $route->gpx_original_name ?? $route->name,
            'sizeKo' => $route->gpx_size_ko,
            'distanceKm' => $route->distance_km,
            'dplus' => $route->dplus_m,
            'dmoins' => $route->dmoins_m,
            'altMin' => $route->alt_min_m,
            'altMax' => $route->alt_max_m,
            'pointCount' => $route->point_count,
            'durationMin' => $route->duration_min,
        ], fn ($v) => $v !== null);
    }

    public function render()
    {
        return view('livewire.gpx-route-form', [
            'disciplines' => Discipline::query()->whereNull('archived_at')->orderBy('sort_order')->get(),
            'locations' => Location::query()->where('is_archived', false)->orderBy('name')->get(),
        ]);
    }
}
