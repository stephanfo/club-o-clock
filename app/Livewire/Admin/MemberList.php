<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\User;
use App\Services\MemberImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

// Page « Adhérents » (PRD §4.17.1) — porté de screen-admin.jsx AdminAdherents.
// Liste paginée filtrable : recherche nom/email/catégorie, filtres accès + rôle, compteurs
// d'en-tête. Admin uniquement (Gate manage-members). « Charger plus » incrémente la fenêtre.
#[Layout('layouts.app')]
#[Title('Adhérents')]
class MemberList extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-members';
    }

    use WithFileUploads;

    #[Url]
    public string $search = '';

    /** Filtre d'accès : all | active | suspended | eligible (éligible suppression, J6.3). */
    #[Url]
    public string $access = 'all';

    /** Filtre de rôle : all | athlete | coach | admin. */
    #[Url]
    public string $role = 'all';

    /** Taille de la fenêtre paginée (« charger plus »). */
    public int $perPage = 20;

    // Import CSV adhérents (§3.1, §4.2 — J6.5) : modale upload + aperçu + rapport tout-ou-rien.
    public bool $showImport = false;

    /** Fichier CSV téléversé (Livewire temporary upload). */
    public $csvFile = null;

    /** Rapport d'aperçu (sans les enregistrements bruts), recalculé au commit. @var array<string,mixed>|null */
    public ?array $importReport = null;

    public function mount(): void {}

    public function openImport(): void
    {
        $this->reset('csvFile', 'importReport');
        $this->resetErrorBag('csvFile');
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->reset('showImport', 'csvFile', 'importReport');
    }

    /** Hook Livewire : à chaque (re)dépôt de fichier, analyse pour l'aperçu (sans muter). */
    public function updatedCsvFile(MemberImportService $import): void
    {
        $this->validate(['csvFile' => ['file', 'max:2048']]); // ≤ 2 Mo

        $report = $import->analyze($this->csvFile->get());
        // Les enregistrements bruts ne servent qu'au commit (ré-analysé) : hors de l'état Livewire.
        $this->importReport = Arr::except($report, 'rows');
    }

    /** Commit tout-ou-rien : ré-analyse le fichier puis crée/met à jour en lot (§4.2). */
    public function import(MemberImportService $import): void
    {
        if ($this->csvFile === null) {
            return;
        }

        $report = $import->analyze($this->csvFile->get());
        if ($report['fatal'] !== null || $report['errors'] !== []) {
            // Le bouton est verrouillé côté UI ; garde serveur si l'état a bougé entre-temps.
            $this->importReport = Arr::except($report, 'rows');

            return;
        }

        $result = $import->commit($report, auth()->user());
        $this->closeImport();
        $this->perPage = 20;
        session()->flash('status', "Import terminé : {$result['created']} adhérent(s) créé(s), {$result['updated']} mis à jour.");
    }

    public function updated(string $name): void
    {
        // Tout changement de filtre/recherche réinitialise la fenêtre de pagination.
        if (in_array($name, ['search', 'access', 'role'], true)) {
            $this->perPage = 20;
        }
    }

    public function setAccess(string $value): void
    {
        $this->access = $value;
    }

    public function setRole(string $value): void
    {
        $this->role = $value;
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    private function baseQuery(): Builder
    {
        return User::query()
            ->whereNull('anonymized_at')
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.$this->search.'%';
                $q->where(function (Builder $sub) use ($term) {
                    $sub->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term])
                        ->orWhere('email', 'like', $term)
                        ->orWhereHas('categories', fn (Builder $c) => $c->where('label', 'like', $term));
                });
            })
            ->when($this->access === 'active', fn (Builder $q) => $q->where('is_active', true)->where('athlete_access_suspended', false))
            ->when($this->access === 'suspended', fn (Builder $q) => $q->where('athlete_access_suspended', true))
            // « Statut suppression » : tout le cycle de vie (tampon + éligibles J+7), §4.3.
            ->when($this->access === 'pending', fn (Builder $q) => $q->deletionPending())
            ->when($this->access === 'eligible', fn (Builder $q) => $q->deletionEligible())
            ->when($this->role !== 'all', fn (Builder $q) => $q->whereJsonContains('roles', $this->role));
    }

    public function render()
    {
        $query = $this->baseQuery()
            ->with(['categories', 'guardian'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        $total = (clone $query)->toBase()->getCountForPagination();
        $members = $query->take($this->perPage)->get();

        // Compteurs d'en-tête (sur tous les adhérents non anonymisés, indépendants des filtres).
        // pending = demande en cours (tampon) ; eligible = tampon J+7 écoulé, actionnable (§4.3).
        $counts = [
            'active' => User::query()->whereNull('anonymized_at')->where('is_active', true)->where('athlete_access_suspended', false)->count(),
            'suspended' => User::query()->whereNull('anonymized_at')->where('athlete_access_suspended', true)->count(),
            'pending' => User::deletionPending()->count(),
            'eligible' => User::deletionEligible()->count(),
            'guardians' => User::query()->whereNull('anonymized_at')->has('wards')->count(),
        ];

        return view('livewire.admin.member-list', [
            'members' => $members,
            'total' => $total,
            'counts' => $counts,
        ]);
    }
}
