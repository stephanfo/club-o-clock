<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\RegistrationService;
use Illuminate\Support\Collection;
use RuntimeException;

// Inscriptions : self-service au nom du sujet (§4.9, §4.10) + gestion par le bureau (§4.9.7)
// + déblocage du quota de la séance (mécanisme C §4.10.4, #66).
trait ManagesEnrollment
{
    /** Bandeau « dépassement de quota » affiché tant que l'athlète n'a pas confirmé (§4.10.3). */
    public bool $confirmingQuota = false;

    /**
     * Place que le sujet rendrait s'il obtenait plus tard une place ici (#103) — nommée dans le
     * dialog de quota, pour qu'il sache à quoi il s'engage en rejoignant la file.
     */
    public ?string $quotaRequeue = null;

    /**
     * S'inscrire / rejoindre la liste d'attente (§4.9, §4.10).
     * Un dépassement de quota lève QUOTA_NEEDS_CONFIRM → on montre le bandeau de confirmation.
     */
    public function enroll(RegistrationService $service, bool $confirmQuota = false): void
    {
        $target = $this->subject();
        $this->authorize('enroll', [$this->session, $target]);

        try {
            $registration = $service->register($this->session, $target, auth()->user(), confirmQuota: $confirmQuota);
            $this->confirmingQuota = false;

            if (($rendue = self::requeuedLabel($registration)) !== null) {
                $qui = $target->id === auth()->id() ? 'Ta place' : 'La place de '.$target->first_name;
                session()->flash('status', "Inscription confirmée. {$qui} à {$rendue} repasse en liste d'attente, pour laisser la place à quelqu'un qui attendait.");
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                $this->confirmingQuota = true; // demande explicite avant waitlist quota_exceeded.
                $this->quotaRequeue = self::placeLabel($service->placeToRequeue($target, $this->session));
            } else {
                // Sentinelle possible malgré l'authorize (course : catégorie/ciblage modifié entre-temps).
                session()->flash('warn', $this->translateRegError($e->getMessage()));
            }
        }

        $this->refreshSession();
    }

    /**
     * Avertissement avant de s'inscrire sur une séance au quota débloqué (#103) : l'inscription y
     * passe sans dialog de quota, mais rend l'autre place de la semaine. Null si rien à rendre.
     */
    protected function requeueNotice(): ?string
    {
        $subject = $this->subject();

        if (! $this->session->isQuotaReleased() || $this->session->hasStarted()) {
            return null;
        }

        $reg = $this->session->registrations->firstWhere('user_id', $subject->id);
        if ($reg !== null && $reg->status !== 'cancelled') {
            return null;
        }

        return self::placeLabel(app(RegistrationService::class)->placeToRequeue($subject, $this->session));
    }

    /** « « Natation » du ven. 3 oct. » : la séance d'une place, au format dense (heure club). */
    private static function placeLabel(?Registration $registration): ?string
    {
        $session = $registration?->session;

        return $session === null ? null : '« '.$session->title.' » du '
            .$session->start_at->copy()->setTimezone(ClubSettings::current()->timezone)->locale('fr')->isoFormat('ddd D MMM');
    }

    /** Place rendue par une inscription qui vient d'aboutir (#103), ou null. */
    private static function requeuedLabel(Registration $registration): ?string
    {
        return $registration->relationLoaded('requeued') ? self::placeLabel($registration->getRelation('requeued')) : null;
    }

    public function cancelQuotaConfirm(): void
    {
        $this->confirmingQuota = false;
        $this->quotaRequeue = null;
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
            $registration = $service->register($this->session, $target, auth()->user());
            $this->pickingAthlete = false;
            $rendue = self::requeuedLabel($registration);
            session()->flash('status', $target->fullName().' inscrit·e.'
                .($rendue !== null ? " Sa place à {$rendue} repasse en liste d'attente." : ''));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                $tag = $this->session->quotaTag;
                $this->pickingAthlete = false;
                $this->athleteQuotaConfirm = [
                    'user_id' => $userId,
                    'count' => $tag ? $quota->weeklyCount($target, $tag->id, $this->session->start_at, $this->session->id) : null,
                    'max' => $tag?->max_per_week,
                    'tag' => $tag?->label,
                    'requeue' => self::placeLabel($service->placeToRequeue($target, $this->session)),
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

    // ─────────────────────────  Déblocage du quota (mécanisme C §4.10.4, #66)  ─────────────────

    /** Dialog « Débloquer le quota » ouvert ? */
    public bool $confirmingRelease = false;

    /** Accusé de réception des notifications aux promus — arme le bouton quand il y en a. */
    public bool $releaseCheck = false;

    /** Motif optionnel, partagé par le geste et chaque promotion (AuditLog). */
    public string $releaseMotif = '';

    public function openReleaseConfirm(): void
    {
        $this->authorize('update', $this->session); // coach/admin
        $this->releaseCheck = false;   // jamais pré-cochée : la case n'a de valeur que relue
        $this->releaseMotif = '';
        $this->confirmingRelease = true;
    }

    public function dismissReleaseConfirm(): void
    {
        $this->confirmingRelease = false;
        $this->releaseCheck = false;
    }

    public function releaseQuota(RegistrationService $service): void
    {
        $this->authorize('update', $this->session);
        $motif = mb_substr(trim($this->releaseMotif), 0, 140) ?: null;

        try {
            // L'accusé est relu CÔTÉ SERVICE, sous verrou : le bouton grisé ne garde rien, et la
            // file a pu grossir depuis l'ouverture du dialog.
            $n = $service->releaseQuota($this->session, auth()->user(), $motif, acknowledged: $this->releaseCheck);
            $this->dismissReleaseConfirm();
            session()->flash('status', match ($n) {
                0 => 'Quota débloqué jusqu\'à la séance.',
                1 => 'Quota débloqué : 1 athlète promu·e.',
                default => "Quota débloqué : {$n} athlètes promu·e·s.",
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RegistrationService::RELEASE_NEEDS_ACK) {
                // Le dialog reste ouvert sur la liste à jour : le coach relit avant de confirmer.
                $this->releaseCheck = false;
                session()->flash('warn', 'La file d\'attente a changé : vérifie la liste des promu·e·s avant de confirmer.');
            } else {
                $this->dismissReleaseConfirm();
                session()->flash('warn', $e->getMessage());
            }
        }

        // Le service a écrit l'état sur SA copie verrouillée : relire les colonnes, pas seulement
        // les relations, sans quoi la fiche afficherait l'ancien état jusqu'à la requête suivante.
        $this->session->refresh();
        $this->refreshSession();
    }

    public function closeQuota(RegistrationService $service): void
    {
        $this->authorize('update', $this->session);
        $service->closeQuota($this->session, auth()->user());
        session()->flash('status', 'Quota refermé : les inscrit·e·s promu·e·s le restent.');

        $this->session->refresh(); // cf. releaseQuota()
        $this->refreshSession();
    }
}
