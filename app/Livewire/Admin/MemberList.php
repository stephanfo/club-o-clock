<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\MemberImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

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

    /** Envoyer les invitations d'activation aux comptes créés par l'import (§4.1.3). */
    public bool $sendInvitations = true;

    /** Modale de confirmation de l'envoi de masse (elle notifie des tiers → x-dialog). */
    public bool $confirmingBulkInvite = false;

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

    // --- Invitations en attente (§4.1.3) ---

    /** Plafond par clic : borne l'à-coup sur l'outbox et rend l'action réexécutable sans surprise. */
    public const BULK_INVITE_CAP = 500;

    public function confirmBulkInvite(): void
    {
        $this->confirmingBulkInvite = true;
    }

    /**
     * Invite en masse les adhérents jamais entrés (import silencieux, invitation expirée sans clic).
     *
     * Mise en file, jamais d'envoi direct : c'est le même raisonnement que l'import. Idempotent —
     * un compte qui a déjà une invitation vivante n'est pas re-sollicité, donc deux clics de suite
     * n'envoient rien la seconde fois.
     */
    public function sendPendingInvitations(InvitationService $invitations): void
    {
        // Plafond posé EN BASE : la borne doit s'appliquer à la requête, pas à une collection déjà
        // hydratée — sinon un club au gros import charge tous ses adhérents en mémoire pour n'en
        // garder que 500, sur un mutualisé à memory_limit basse.
        $cibles = $invitations->awaitingInvitation(self::BULK_INVITE_CAP);

        $this->confirmingBulkInvite = false;

        if ($cibles->isEmpty()) {
            session()->flash('status', 'Aucun adhérent en attente d’invitation.');

            return;
        }

        // On compte ce qui est RÉELLEMENT parti : un canal coupé ou une préférence en pause fait
        // refuser l'envoi, et annoncer 500 invitations quand aucune n'a été mise en file serait un
        // mensonge que rien ne viendrait corriger ensuite.
        $misesEnFile = 0;
        $refusees = 0;
        foreach ($cibles as $membre) {
            try {
                $invitations->sendToMember($membre, auth()->user(), immediate: false);
                $misesEnFile++;
            } catch (RuntimeException) {
                $refusees++;
            }
        }

        if ($misesEnFile === 0) {
            session()->flash('warn', 'Aucune invitation n’a pu partir — vérifie le canal email (Admin → Réglages).');

            return;
        }

        session()->flash('status', $misesEnFile.' invitation(s) mise(s) en file (Admin → Envois).'
            .($refusees > 0 ? ' '.$refusees.' non envoyée(s) : canal ou préférence bloquant.' : ''));
    }

    /** Commit tout-ou-rien : ré-analyse le fichier puis crée/met à jour en lot (§4.2). */
    public function import(MemberImportService $import, InvitationService $invitations): void
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

        // APRÈS le commit, donc hors de sa transaction : on n'émet rien qui pourrait être annulé.
        // Mise en FILE (immediate: false) et non envoi direct — 200 envois SMTP synchrones dans une
        // requête, c'est un timeout garanti sur mutualisé. Le cron draine par lots toutes les 5 min.
        $queued = 0;
        $refusees = 0;
        if ($this->sendInvitations) {
            foreach (User::whereKey($result['created_ids'])->whereNotNull('email')->get() as $nouveau) {
                try {
                    $invitations->sendToMember($nouveau, auth()->user(), immediate: false);
                    $queued++;
                } catch (RuntimeException) {
                    // Canal coupé ou préférence bloquante : les fiches sont créées, on ne perd pas
                    // l'import pour autant — mais on ne prétend pas avoir envoyé.
                    $refusees++;
                }
            }
        }

        $this->closeImport();
        $this->perPage = 20;
        session()->flash('status', "Import terminé : {$result['created']} adhérent(s) créé(s), {$result['updated']} mis à jour."
            .($queued > 0 ? " {$queued} invitation(s) mise(s) en file (Admin → Envois)." : '')
            .($refusees > 0 ? " {$refusees} invitation(s) non envoyée(s) : canal email coupé ou préférence bloquante." : ''));
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
            // Comptes jamais entrés et sans invitation en cours : le bouton de rattrapage n'apparaît
            // que s'il a quelque chose à faire. COUNT en base : ce render rejoue à chaque frappe
            // dans la recherche, hydrater tous les modèles pour n'afficher qu'un nombre se payait
            // à chaque caractère.
            'awaiting' => app(InvitationService::class)->awaitingInvitationQuery()->count(),
        ]);
    }
}
