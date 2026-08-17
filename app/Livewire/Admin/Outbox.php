<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\OutboxAdminService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Écran bureau de gestion de l'outbox (PRD §4.15.6 — J8.3). Consultation filtrée (statut, canal,
// type, destinataire) + détail, et rattrapage : annulation des `pending`, envoi manuel immédiat,
// rejeu des `failed`. Admin uniquement (Gate manage-outbox). La consultation ne ré-émet jamais ;
// seules les actions explicites agissent sur la file, et chacune est tracée (§4.15.6).
#[Layout('layouts.app')]
#[Title('Envois')]
class Outbox extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-outbox';
    }

    /** Fenêtre « charger plus ». */
    public int $perPage = 25;

    /** Filtre statut : '' (tous) | pending | sent | failed | cancelled. */
    #[Url]
    public string $status = '';

    /** Filtre canal : '' | push | email. */
    #[Url]
    public string $channel = '';

    /** Filtre type (§4.15.2). */
    #[Url]
    public string $type = '';

    /** Destinataire sélectionné (autocomplete) — id + libellé. */
    #[Url]
    public ?int $userId = null;

    public string $userLabel = '';

    public string $userQuery = '';

    /** Lignes cochées (multi-select). @var array<int,int> */
    public array $selected = [];

    /** Drawer détail : ligne ouverte (id) ou null. */
    public ?int $detailId = null;

    public function mount(): void {}

    public function updating($name): void
    {
        // Tout changement de filtre réinitialise pagination + sélection (ids potentiellement hors page).
        if (in_array($name, ['status', 'channel', 'type', 'userId'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function selectUser(int $id): void
    {
        if ($u = User::find($id)) {
            $this->userId = $id;
            $this->userLabel = $u->fullName();
        }
        $this->userQuery = '';
        $this->resetPage();
        $this->selected = [];
    }

    public function clearUser(): void
    {
        $this->reset('userId', 'userLabel', 'userQuery');
        $this->resetPage();
        $this->selected = [];
    }

    public function resetFilters(): void
    {
        $this->reset('status', 'channel', 'type', 'userId', 'userLabel', 'userQuery', 'selected');
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 25;
    }

    private function resetPage(): void
    {
        $this->perPage = 25;
    }

    private function filters(): array
    {
        return array_filter([
            'status' => $this->status ?: null,
            'channel' => $this->channel ?: null,
            'type' => $this->type ?: null,
            'user_id' => $this->userId,
        ], fn ($v) => $v !== null);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return array_values(array_map('intval', $this->selected));
    }

    // ── Actions de rattrapage (§4.15.6) ──

    /** Dialog de confirmation d'annulation : 'all' | 'selected' | 'detail' | null.
     *  Annuler des envois est irréversible et silencieux pour les destinataires → dialog stylé
     *  avec portée explicite, pas un confirm() natif (revue UX 2026-07-11, constat n°4). */
    public ?string $cancelConfirm = null;

    public function askCancel(string $scope): void
    {
        if (in_array($scope, ['all', 'selected', 'detail'], true)) {
            $this->cancelConfirm = $scope;
        }
    }

    public function dismissCancel(): void
    {
        $this->cancelConfirm = null;
    }

    /** Nombre d'envois sélectionnés réellement annulables (encore `pending`) — pour la portée
     *  affichée dans le dialog : count($selected) surestimerait (lignes déjà parties/annulées). */
    private function cancellableSelectedCount(): int
    {
        $ids = $this->selectedIds();

        return $ids === []
            ? 0
            : NotificationOutbox::whereIn('id', $ids)->where('status', 'pending')->count();
    }

    public function confirmCancel(OutboxAdminService $service): void
    {
        $scope = $this->cancelConfirm;
        $this->cancelConfirm = null;

        match ($scope) {
            'all' => $this->cancelAllPending($service),
            'selected' => $this->cancelSelected($service),
            'detail' => $this->cancelDetail($service),
            default => null,
        };
    }

    public function cancelSelected(OutboxAdminService $service): void
    {
        $n = $service->cancel($this->selectedIds(), auth()->user());
        $this->afterAction($n > 0 ? "{$n} envoi(s) annulé(s)." : 'Aucun envoi en attente à annuler.');
    }

    public function pushSelected(OutboxAdminService $service): void
    {
        $stats = $service->pushNow($this->selectedIds(), auth()->user());
        $this->afterAction($this->pushMessage($stats));
    }

    public function retrySelected(OutboxAdminService $service): void
    {
        $n = $service->retry($this->selectedIds(), auth()->user());
        $this->afterAction($n > 0 ? "{$n} envoi(s) remis en file." : 'Aucun envoi en échec à rejouer.');
    }

    /** Pousse TOUS les `pending` du filtre courant (§4.15.6 « un ou tous »). */
    public function pushAllPending(OutboxAdminService $service): void
    {
        $stats = $service->pushNow($service->pendingIds($this->filters()), auth()->user());
        $this->afterAction($this->pushMessage($stats));
    }

    /** Annule TOUS les `pending` du filtre courant. */
    public function cancelAllPending(OutboxAdminService $service): void
    {
        $n = $service->cancel($service->pendingIds($this->filters()), auth()->user());
        $this->afterAction($n > 0 ? "{$n} envoi(s) annulé(s)." : 'Aucun envoi en attente à annuler.');
    }

    private function afterAction(string $message): void
    {
        $this->selected = [];
        session()->flash('status', $message);
    }

    /**
     * Message d'un envoi manuel. Les lignes annulées faute de canal ouvert (§4.17) sont signalées :
     * sans ça, l'admin lit « 0 envoi(s) poussé(s) » sans comprendre que c'est lui qui a coupé le canal.
     *
     * @param  array{sent:int,retried:int,failed:int,cancelled:int}  $stats
     */
    private function pushMessage(array $stats): string
    {
        $message = "{$stats['sent']} envoi(s) poussé(s).";

        if ($stats['cancelled'] > 0) {
            $message .= " {$stats['cancelled']} annulé(s) : le canal est désactivé dans les paramètres du club.";
        }

        return $message;
    }

    // ── Actions ciblant la ligne ouverte dans le drawer ──

    public function pushDetail(OutboxAdminService $service): void
    {
        if ($this->detailId) {
            $stats = $service->pushNow([$this->detailId], auth()->user());
            session()->flash('status', $this->pushMessage($stats));
        }
        $this->detailId = null;
    }

    public function cancelDetail(OutboxAdminService $service): void
    {
        if ($this->detailId) {
            $n = $service->cancel([$this->detailId], auth()->user());
            session()->flash('status', $n > 0 ? 'Envoi annulé.' : 'Envoi déjà parti, rien à annuler.');
        }
        $this->detailId = null;
    }

    public function retryDetail(OutboxAdminService $service): void
    {
        if ($this->detailId) {
            $n = $service->retry([$this->detailId], auth()->user());
            session()->flash('status', $n > 0 ? 'Envoi remis en file.' : 'Rien à rejouer.');
        }
        $this->detailId = null;
    }

    public function showDetail(int $id): void
    {
        // Defense-in-depth : c'est l'action qui charge le payload dans le drawer (parité Journal).
        $this->detailId = $id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function render(OutboxAdminService $service)
    {
        $page = $service->page($this->filters(), $this->perPage);

        return view('livewire.admin.outbox', [
            'tz' => ClubSettings::current()->timezone,
            'rows' => $page['rows'],
            'total' => $page['total'],
            'detail' => $this->detailId ? NotificationOutbox::with('user')->find($this->detailId) : null,
            'statusLabels' => NotificationOutbox::STATUS_LABELS,
            'typeOptions' => NotificationType::cases(),
            'userSuggestions' => $this->userId ? [] : $this->userSuggestions(),
            // Portée réelle du dialog « selected » (envois encore annulables) — calculée à l'ouverture.
            'cancellableSelected' => $this->cancelConfirm === 'selected' ? $this->cancellableSelectedCount() : 0,
        ]);
    }

    /** Suggestions destinataire (autocomplete) — restreint aux users ayant des lignes outbox. */
    private function userSuggestions(): array
    {
        $q = trim($this->userQuery);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return User::query()
            ->whereIn('id', NotificationOutbox::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->where(fn ($w) => $w->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%"))
            ->orderBy('last_name')
            ->limit(8)
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->fullName()])
            ->all();
    }
}
