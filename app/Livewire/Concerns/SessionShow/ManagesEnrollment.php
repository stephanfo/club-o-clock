<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Models\User;
use App\Services\QuotaService;
use App\Services\RegistrationService;
use Illuminate\Support\Collection;
use RuntimeException;

// Inscriptions : self-service au nom du sujet (§4.9, §4.10) + gestion par le bureau (§4.9.7)
// + déblocage de la file quota (mécanisme C §4.10.4).
trait ManagesEnrollment
{
    /** Bandeau « dépassement de quota » affiché tant que l'athlète n'a pas confirmé (§4.10.3). */
    public bool $confirmingQuota = false;

    /**
     * S'inscrire / rejoindre la liste d'attente (§4.9, §4.10).
     * Un dépassement de quota lève QUOTA_NEEDS_CONFIRM → on montre le bandeau de confirmation.
     */
    public function enroll(RegistrationService $service, bool $confirmQuota = false): void
    {
        $target = $this->subject();
        $this->authorize('enroll', [$this->session, $target]);

        try {
            $service->register($this->session, $target, auth()->user(), confirmQuota: $confirmQuota);
            $this->confirmingQuota = false;
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                $this->confirmingQuota = true; // demande explicite avant waitlist quota_exceeded.
            } else {
                // Sentinelle possible malgré l'authorize (course : catégorie/ciblage modifié entre-temps).
                session()->flash('warn', $this->translateRegError($e->getMessage()));
            }
        }

        $this->refreshSession();
    }

    public function cancelQuotaConfirm(): void
    {
        $this->confirmingQuota = false;
    }

    /** Se désinscrire (§4.9) — peut promouvoir le 1er en liste d'attente (mécanismes A/B). */
    public function unenroll(RegistrationService $service): void
    {
        $target = $this->subject();
        $this->authorize('unenroll', [$this->session, $target]);

        try {
            $service->cancel($this->session, $target, auth()->user());
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->confirmingQuota = false;
        $this->refreshSession();
    }

    // ─────────────────────────  Inscription / retrait d'un athlète par le bureau (§4.9.7)  ─────

    /** Sélecteur « Inscrire un athlète » (§4.9.7) ouvert ? */
    public bool $pickingAthlete = false;

    /** Filtre de recherche du sélecteur d'athlètes (le proto FInscrits filtre par requête). */
    public string $athleteSearch = '';

    /** Dialog quota dépassé (§4.9.7 cas b) : ['user_id'=>…,'count'=>N,'max'=>N,'tag'=>…,'motif'=>''] ou null. */
    public ?array $athleteQuotaConfirm = null;

    public function openAthletePicker(): void
    {
        $this->authorize('enrollOther', $this->session);
        $this->athleteSearch = '';
        $this->pickingAthlete = true;
    }

    public function closeAthletePicker(): void
    {
        $this->pickingAthlete = false;
        $this->athleteSearch = '';
    }

    /**
     * Inscrit un athlète tiers (§4.9.7). Sous quota → inscription normale. Quota dépassé →
     * QUOTA_NEEDS_CONFIRM : on ouvre le dialog explicite au coach (a) file quota / (b) override,
     * plutôt que d'auto-confirmer.
     */
    public function enrollAthlete(RegistrationService $service, QuotaService $quota, int $userId): void
    {
        $this->authorize('enrollOther', $this->session);
        $target = User::findOrFail($userId);

        try {
            $service->register($this->session, $target, auth()->user());
            $this->pickingAthlete = false;
            session()->flash('status', $target->fullName().' inscrit·e.');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                $tag = $this->session->quotaTag;
                $this->pickingAthlete = false;
                $this->athleteQuotaConfirm = [
                    'user_id' => $userId,
                    'count' => $tag ? $quota->weeklyCount($target, $tag->id, $this->session->start_at, $this->session->id) : null,
                    'max' => $tag?->max_per_week,
                    'tag' => $tag?->label,
                    'motif' => '',
                ];
            } else {
                session()->flash('warn', $this->translateRegError($e->getMessage()));
            }
        }

        $this->refreshSession();
    }

    /**
     * Résolution du dialog quota (§4.9.7) : $override = (b) forcer via override §4.10.5 (compte
     * dans le quota, badge), sinon (a) placer en file quota_exceeded.
     */
    public function confirmAthleteQuota(RegistrationService $service, bool $override): void
    {
        $this->authorize('enrollOther', $this->session);
        if ($this->athleteQuotaConfirm === null) {
            return;
        }

        $target = User::findOrFail($this->athleteQuotaConfirm['user_id']);
        // Motif libre borné à 140, comme le motif apéro (§4.14.2). Vide → null.
        $motif = mb_substr(trim((string) ($this->athleteQuotaConfirm['motif'] ?? '')), 0, 140) ?: null;

        try {
            if ($override) {
                $service->overrideRegister($this->session, $target, auth()->user(), $motif);
                session()->flash('status', $target->fullName().' inscrit·e (override).');
            } else {
                $service->register($this->session, $target, auth()->user(), confirmQuota: true);
                session()->flash('status', $target->fullName().' placé·e en file quota.');
            }
            $this->athleteQuotaConfirm = null;
        } catch (RuntimeException $e) {
            session()->flash('warn', $this->translateRegError($e->getMessage()));
        }

        $this->refreshSession();
    }

    public function cancelAthleteQuota(): void
    {
        $this->athleteQuotaConfirm = null;
    }

    /** Retire un athlète tiers (inscrit ou en waitlist) — promotion FIFO via cancel() (mécanisme A). */
    public function removeAthlete(RegistrationService $service, int $userId): void
    {
        $this->authorize('unenrollOther', $this->session);
        $target = User::findOrFail($userId);

        try {
            $service->cancel($this->session, $target, auth()->user());
            session()->flash('status', $target->fullName().' retiré·e.');
        } catch (RuntimeException $e) {
            session()->flash('warn', $this->translateRegError($e->getMessage()));
        }

        $this->refreshSession();
    }

    /**
     * Athlètes inscriptibles par le bureau (§4.9.7) : rôle athlète, actifs, accès non suspendu
     * (§4.4 — un suspendu ne s'inscrit sur aucun kind, même par le bureau), pas déjà inscrits
     * activement sur la séance, pas encadrants. Filtre nom/prénom sur $athleteSearch.
     *
     * @return Collection<int, User>
     */
    private function selectableAthletes()
    {
        $registeredIds = $this->session->registrations
            ->whereIn('status', ['participating', 'waitlist'])
            ->pluck('user_id')->all();
        $coachIds = $this->session->coaches->pluck('id')->all();
        $q = trim($this->athleteSearch);

        return User::query()
            ->where('is_active', true)
            ->where('athlete_access_suspended', false)
            ->whereJsonContains('roles', 'athlete')
            ->whereNotIn('id', array_merge($registeredIds, $coachIds))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$q}%")
                ->orWhere('last_name', 'like', "%{$q}%")))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }

    /** Mécanisme C (§4.10.4) : déblocage coach de la file quota_exceeded. */
    public function fillQuota(RegistrationService $service, ?string $motif = null): void
    {
        $this->authorize('update', $this->session); // coach/admin
        $motif = $motif === null ? null : (mb_substr(trim($motif), 0, 140) ?: null);

        try {
            $n = $service->fillFromQuotaExceeded($this->session, auth()->user(), $motif);
            session()->flash('status', $n > 0 ? "{$n} athlète·s promu·e·s." : 'Aucune place à débloquer.');
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->refreshSession();
    }
}
