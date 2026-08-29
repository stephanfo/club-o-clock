<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithSubject;
use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use App\Services\GuardianshipService;
use App\Services\QuotaService;
use App\Support\SubjectContext;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

// « Mes enfants » (PRD §4.2, porté de screen-parent.jsx ParentChildren / ParentChildrenDesktop).
// Le parent garant consulte et gère ses enfants P1/P2 : prochaine séance + inscription (via le
// sujet §4.2), semaine courante, transitions de tutelle (P1→P2 invitation, P2→P3 rupture).
// La création d'un enfant reste admin-only (§4.1.3) — pas de « Ajouter un enfant » ici.
#[Layout('layouts.app')]
#[Title('Mes enfants')]
class ParentChildren extends Component
{
    use WithSubject;

    /** Dialog « Accès autonome » (P1→P2, §4.2.1) : ['ward_id' => …, 'email' => ''] ou null. */
    public ?array $inviteDialog = null;

    /** Dialog « Rompre la tutelle » (P2→P3, §4.2.2) : ward_id ou null. */
    public ?int $severDialog = null;

    /** Case « j'ai compris » du dialog de rupture — arme le bouton (motif §4.17). */
    public bool $severCheck = false;

    public function mount(): void
    {
        abort_unless(SubjectContext::isGuardian(auth()->user()), 403);
    }

    /** Enfant garanti ou 404 — toute action de cette page est bornée aux pupilles du parent. */
    private function ward(int $id): User
    {
        return $this->subjectWards()->firstWhere('id', $id) ?? abort(404);
    }

    // Ouvrir une séance AU NOM de l'enfant se fait via le lien de la carte (?as=, cf. x-session-card
    // :linkAs) : le sujet est posé dans SessionShow::mount() en une seule requête GET (§4.2). Pas de
    // handler wire:click ici — il entrait en course avec le wire:navigate interne de la carte.

    // ── P1 → P2 : accès autonome (§4.2.1) ──

    public function openInvite(int $wardId): void
    {
        $ward = $this->ward($wardId);
        $this->inviteDialog = ['ward_id' => $ward->id, 'email' => $ward->email ?? ''];
    }

    public function sendInvite(GuardianshipService $service): void
    {
        if ($this->inviteDialog === null) {
            return;
        }
        $ward = $this->ward($this->inviteDialog['ward_id']);

        try {
            $service->invite($ward, auth()->user(), $this->inviteDialog['email'] ?: null);
        } catch (RuntimeException $e) {
            $this->addError('inviteDialog.email', $e->getMessage());

            return;
        }

        $this->inviteDialog = null;
        session()->flash('status', 'Invitation envoyée — '.$ward->first_name.' pourra activer son compte (lien valable '.ClubSettings::current()->invitation_link_days.' jours).');
    }

    public function cancelInvite(): void
    {
        $this->inviteDialog = null;
        $this->resetErrorBag();
    }

    // ── P2 → P3 : rupture de tutelle (§4.2.2) ──

    public function openSever(int $wardId): void
    {
        $this->severCheck = false;
        $this->severDialog = $this->ward($wardId)->id;
    }

    public function confirmSever(GuardianshipService $service): void
    {
        // Accusé de réception explicite (motif §4.17) : la rupture notifie le parent ET l'enfant,
        // et l'envoi ne se dédit pas. Garde serveur, pas seulement bouton grisé.
        if ($this->severDialog === null || ! $this->severCheck) {
            return;
        }
        $ward = $this->ward($this->severDialog);

        $service->sever($ward, auth()->user());
        $this->severDialog = null;
        $this->severCheck = false;
        session()->flash('status', 'Tutelle rompue — '.$ward->first_name.' est désormais autonome.');
    }

    public function cancelSever(): void
    {
        $this->severDialog = null;
        $this->severCheck = false;
    }

    public function render(QuotaService $quota)
    {
        $tz = ClubSettings::current()->timezone;
        $now = Carbon::now($tz);
        // Bornes SQL en UTC (start_at stocké en UTC ; sérialisation Carbon sans conversion de fuseau).
        $nowUtc = $now->copy()->utc();

        $cards = $this->subjectWards()->map(function (User $ward) use ($quota, $now, $nowUtc) {
            // Les 3 prochaines séances où l'enfant est activement inscrit ; à défaut, prochaine
            // séance ouverte (bouton « Inscrire » — le flux passe par la fiche avec le sujet posé).
            $nextRegistered = Session::query()
                ->with(['discipline', 'location', 'registrations', 'activeAperoFlags', 'quotaTag'])
                ->whereNull('cancelled_at')
                ->where('start_at', '>=', $nowUtc)
                ->whereHas('registrations', fn ($q) => $q->where('user_id', $ward->id)
                    ->whereIn('status', ['participating', 'waitlist']))
                ->orderBy('start_at')
                ->limit(3)
                ->get();
            $nextOpen = $nextRegistered->isNotEmpty() ? null : Session::query()
                ->with(['discipline', 'location', 'registrations', 'activeAperoFlags', 'quotaTag'])
                ->whereNull('cancelled_at')
                ->where('kind', 'training')
                ->where('start_at', '>=', $nowUtc)
                ->whereDoesntHave('registrations', fn ($q) => $q->where('user_id', $ward->id)
                    ->whereIn('status', ['participating', 'waitlist']))
                ->orderBy('start_at')
                ->first();

            $quotaFull = collect($quota->weeklyUsage($ward, $now))
                ->first(fn ($q) => $q['max'] !== null && $q['used'] >= $q['max']);

            return [
                'ward' => $ward,
                'phase' => SubjectContext::phase($ward),
                'age' => $ward->dob?->age,
                'cat' => $ward->primaryCategory()?->label,
                // Séances inscrites (jusqu'à 3), ou repli sur la séance ouverte à inscrire.
                // Le chip de statut est calculé par x-session-card via viewAs (registrations chargées).
                'nextRegistered' => $nextRegistered,
                'nextOpen' => $nextOpen,
                'quotaFull' => $quotaFull,
            ];
        });

        return view('livewire.parent-children', [
            'cards' => $cards,
            'tz' => $tz,
        ]);
    }
}
