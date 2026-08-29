<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesAdminGate;
use App\Models\Category;
use App\Models\Qualification;
use App\Models\User;
use App\Services\GuardianshipService;
use App\Services\InvitationService;
use App\Services\MemberService;
use App\Services\SeasonService;
use App\Support\AgeCategory;
use App\Support\DemoMode;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

// Fiche détail d'un adhérent (PRD §4.1.3, §4.11.3) — porté de screen-adherent.jsx.
// Édition action-immédiate : chaque geste (rôle, surclassement, qualif) persiste via MemberService
// (toast + log). Carte Accès & sécurité = suppression RGPD (J6.3) + réactivation accès athlète (J6.4).
// Tutelle (J7.7, §4.2) : autonomisation P1→P2 (invitation) + rupture P2→P3 via GuardianshipService.
// Admin uniquement (Gate manage-members).
#[Layout('layouts.app')]
#[Title('Adhérent')]
class MemberShow extends Component
{
    use AuthorizesAdminGate;

    protected function adminGate(): ?string
    {
        return 'manage-members';
    }

    public User $user;

    public string $tab = 'profil';

    // Édition de la date de naissance (action immédiate, §4.1.3). Pré-remplie au mount.
    public bool $editingDob = false;

    public string $dob = '';

    // Édition de l'email de connexion (§4.1.3). Pré-remplie au mount.
    public bool $editingEmail = false;

    public string $email = '';

    // Panneaux d'ajout (UI proto).
    public bool $addingCat = false;

    public bool $addingQual = false;

    // Suppression RGPD (§4.3) : modale de demande (saisie du nom + double validation) et
    // modale de confirmation définitive forte (cliquable à J+7 uniquement).
    public bool $confirmingRequest = false;

    public string $deleteConfirmName = '';

    public bool $confirmingFinal = false;

    // Suspension individuelle de l'accès athlète (§4.4) : modale de conséquences + motif libre,
    // repris tel quel dans l'AuditLog comme pour le geste de masse.
    public bool $confirmingSuspend = false;

    /** Case « j'ai compris » du dialog de suspension — arme le bouton (motif §4.17). */
    public bool $suspendCheck = false;

    public string $suspendMotif = '';

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->dob = $user->dob?->toDateString() ?? '';
        $this->email = $user->email ?? '';
    }

    /** Ouvre le champ d'édition de l'email (réinitialise à la valeur courante). */
    public function editEmail(): void
    {
        $this->email = $this->user->email ?? '';
        $this->resetErrorBag('email');
        $this->editingEmail = true;
    }

    public function cancelEditEmail(): void
    {
        $this->email = $this->user->email ?? '';
        $this->resetErrorBag('email');
        $this->editingEmail = false;
    }

    /**
     * Corrige l'email de connexion (§4.1.3).
     *
     * C'est la contrepartie du risque assumé à la création : l'admin est cru sur parole et l'adresse
     * saisie est marquée vérifiée d'office, donc une coquille donne la prise du compte à un tiers.
     * Sans ce geste, cette coquille n'avait AUCUN chemin de correction, et l'invitation de 30 jours
     * partie à la mauvaise adresse restait vivante.
     *
     * Vider l'adresse est refusé : pour un pupille, ce serait une bascule P2 → P1 silencieuse. La
     * rupture de tutelle a son propre geste, avec ses conséquences affichées.
     */
    public function saveEmail(MemberService $service): void
    {
        $this->validate(
            ['email' => ['required', 'email', 'max:255', 'unique:users,email,'.$this->user->id]],
            [],
            ['email' => 'email'],
        );

        $service->updateEmail($this->user, trim($this->email), auth()->user());
        $this->user->refresh();
        $this->editingEmail = false;
        session()->flash('status', 'Email mis à jour — les liens envoyés à l\'ancienne adresse sont révoqués.');
    }

    /** Ouvre le champ d'édition de la date de naissance (réinitialise à la valeur courante). */
    public function editDob(): void
    {
        $this->dob = $this->user->dob?->toDateString() ?? '';
        $this->resetErrorBag('dob');
        $this->editingDob = true;
    }

    public function cancelEditDob(): void
    {
        $this->dob = $this->user->dob?->toDateString() ?? '';
        $this->resetErrorBag('dob');
        $this->editingDob = false;
    }

    /** Persiste la nouvelle date de naissance (recalcule is_minor + catégorie principale via le service). */
    public function saveDob(MemberService $service): void
    {
        $this->validate(
            ['dob' => ['required', 'date', 'before:today', 'after:1900-01-01']],
            [],
            ['dob' => 'date de naissance'],
        );

        $service->updateDob($this->user, $this->dob, auth()->user());
        $this->user->refresh()->load('categories');
        $this->editingDob = false;
        session()->flash('status', 'Date de naissance mise à jour — catégorie d\'âge recalculée.');
    }

    public function toggleRole(string $role, MemberService $service): void
    {
        $granted = $service->toggleRole($this->user, $role, auth()->user());
        $this->user->refresh();
        session()->flash('status', "Rôle {$role} ".($granted ? 'ajouté' : 'retiré'));
    }

    public function addSurclassement(int $categoryId, MemberService $service): void
    {
        $service->addSurclassement($this->user, Category::findOrFail($categoryId), auth()->user());
        $this->user->load('categories');
        $this->addingCat = false;
        session()->flash('status', 'Surclassement ajouté');
    }

    public function removeSurclassement(int $categoryId, MemberService $service): void
    {
        $service->removeSurclassement($this->user, Category::findOrFail($categoryId), auth()->user());
        $this->user->load('categories');
        session()->flash('status', 'Surclassement retiré');
    }

    /** Date d'expiration optionnelle saisie à l'ajout d'une qualification (vide = sans expiration). */
    public string $newQualExpiry = '';

    /** Qualification choisie à l'étape 1 (chip sélectionnée), en attente de sa date + validation. */
    public ?int $pendingQualId = null;

    /** Étape 1 : sélectionne la qualification à attribuer (avant de fixer la date). */
    public function selectQualification(int $qualificationId): void
    {
        $this->pendingQualId = $qualificationId;
        $this->newQualExpiry = '';
        $this->resetErrorBag('newQualExpiry');
    }

    /** Étape 2 : attribue la qualification sélectionnée avec la date d'expiration (optionnelle) saisie. */
    public function addQualification(MemberService $service): void
    {
        if ($this->pendingQualId === null) {
            return;
        }
        $this->validate(['newQualExpiry' => ['nullable', 'date']], [], ['newQualExpiry' => 'expiration']);

        $service->addQualification($this->user, Qualification::findOrFail($this->pendingQualId), auth()->user(), $this->newQualExpiry ?: null);
        $this->user->load('qualifications');
        $this->cancelAddQualification();
        session()->flash('status', 'Qualification ajoutée');
    }

    public function cancelAddQualification(): void
    {
        $this->addingQual = false;
        $this->pendingQualId = null;
        $this->newQualExpiry = '';
        $this->resetErrorBag('newQualExpiry');
    }

    public function removeQualification(int $qualificationId, MemberService $service): void
    {
        $service->removeQualification($this->user, Qualification::findOrFail($qualificationId), auth()->user());
        $this->user->load('qualifications');
        session()->flash('status', 'Qualification retirée');
    }

    /** Qualification dont la date d'expiration est en cours d'édition (crayon sur la ligne). */
    public ?int $editingQualId = null;

    public string $editQualExpiry = '';

    // NB : la méthode NE DOIT PAS s'appeler editQualExpiry — homonyme de la propriété ci-dessus,
    // Livewire résoudrait $wire.editQualExpiry vers la valeur (string) et non la méthode
    // → « $wire.editQualExpiry is not a function » côté client. D'où le préfixe verbe.
    public function startEditQualExpiry(int $qualificationId): void
    {
        $pivot = $this->user->qualifications->firstWhere('id', $qualificationId)?->pivot;
        $this->editingQualId = $qualificationId;
        $this->editQualExpiry = $pivot?->expires_at ? Carbon::parse($pivot->expires_at)->toDateString() : '';
        $this->resetErrorBag('editQualExpiry');
    }

    public function saveQualExpiry(MemberService $service): void
    {
        if ($this->editingQualId === null) {
            return;
        }
        $this->validate(['editQualExpiry' => ['nullable', 'date']], [], ['editQualExpiry' => 'expiration']);

        $service->setQualificationExpiry($this->user, Qualification::findOrFail($this->editingQualId), $this->editQualExpiry ?: null, auth()->user());
        $this->user->load('qualifications');
        $this->editingQualId = null;
        $this->editQualExpiry = '';
        session()->flash('status', 'Expiration mise à jour');
    }

    public function cancelQualExpiry(): void
    {
        $this->editingQualId = null;
        $this->editQualExpiry = '';
        $this->resetErrorBag('editQualExpiry');
    }

    // --- Tutelle : autonomisation P1→P2 / rupture P2→P3 (§4.2) ---

    /** Email saisi pour ouvrir le compte autonome d'un mineur P1. */
    public string $wardEmail = '';

    /** Dialog de confirmation de rupture du lien de tutelle. */
    public bool $confirmingSever = false;

    /** P1 → P2 : crée l'invitation d'activation (email envoyé en J8). */
    public function inviteWard(GuardianshipService $service): void
    {
        try {
            $service->invite($this->user, auth()->user(), $this->wardEmail ?: null);
            $this->wardEmail = '';
            $this->user->refresh();
            session()->flash('status', 'Invitation d\'activation créée — l\'email sera envoyé à l\'enfant.');
        } catch (RuntimeException $e) {
            $this->addError('wardEmail', $e->getMessage());
        }
    }

    /**
     * P2 → P3 : rompt le lien de tutelle après confirmation. Le refus P1 remonte en flash : le
     * bouton est masqué dans ce cas, mais l'appel reste atteignable sur état périmé (second onglet,
     * page rejouée) — sans capture, l'admin verrait une 500.
     */
    public function severGuardianship(GuardianshipService $service): void
    {
        try {
            $service->sever($this->user, auth()->user());
            session()->flash('status', 'Lien de tutelle rompu — l\'athlète est désormais autonome (P3).');
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }

        $this->confirmingSever = false;
        $this->user->refresh();
    }

    /** Garant sélectionné pour rattacher un mineur sans tutelle (geste de rattrapage admin). */
    public ?int $linkGuardianId = null;

    /** Rattache ce mineur sans garant à un adulte actif (GuardianshipService::link, AuditLog). */
    public function linkGuardian(GuardianshipService $service): void
    {
        $guardian = User::find($this->linkGuardianId);
        if (! $guardian) {
            $this->addError('linkGuardianId', 'Choisis un garant.');

            return;
        }

        try {
            $service->link($this->user, $guardian, auth()->user());
            $this->linkGuardianId = null;
            $this->user->refresh();
            session()->flash('status', 'Garant rattaché — '.$guardian->fullName().' gère désormais la tutelle.');
        } catch (RuntimeException $e) {
            $this->addError('linkGuardianId', $e->getMessage());
        }
    }

    /** Symétrique côté parent : pupille (mineur sans garant) sélectionné pour un rattachement à CET adulte. */
    public ?int $linkWardId = null;

    /** Ouvre/ferme le sélecteur « ajouter un pupille » depuis la fiche du parent. */
    public bool $addingWard = false;

    /** Rattache un mineur sans garant à cet adhérent (miroir de linkGuardian, vu depuis le parent). */
    public function linkWard(GuardianshipService $service): void
    {
        $ward = User::find($this->linkWardId);
        if (! $ward) {
            $this->addError('linkWardId', 'Choisis un enfant à rattacher.');

            return;
        }

        try {
            $service->link($ward, $this->user, auth()->user());
            $this->linkWardId = null;
            $this->addingWard = false;
            $this->user->refresh();
            session()->flash('status', 'Pupille rattaché — '.$this->user->fullName().' gère désormais la tutelle de '.$ward->fullName().'.');
        } catch (RuntimeException $e) {
            $this->addError('linkWardId', $e->getMessage());
        }
    }

    // --- Bascule de saison : réactivation individuelle (§4.4) ---

    /**
     * Suspend l'accès athlète de CET adhérent (§4.4). Pendant individuel de la bascule de saison :
     * il fallait jusqu'ici suspendre tout le club pour écarter une seule personne.
     *
     * Modale de conséquences et non `wire:confirm` : le geste annule des inscriptions futures, donc
     * il libère des places et fait remonter des tiers depuis la file d'attente — qui, eux, sont
     * notifiés. C'est le critère de la convention (destructif ou notifiant des tiers).
     */
    /** Ouvre le dialog de suspension en désarmant la case : jamais pré-cochée d'une fois sur l'autre. */
    public function openSuspendConfirm(): void
    {
        $this->suspendCheck = false;
        $this->confirmingSuspend = true;
    }

    /**
     * Ferme le dialog en désarmant la case — bouton « Annuler », clic sur le voile, touche Échap.
     * Pendant de dismissCancelConfirm() et cancelSever() : la case ne doit pas survivre au dialog,
     * même si openSuspendConfirm() la remettrait à zéro à la réouverture.
     */
    public function dismissSuspendConfirm(): void
    {
        $this->confirmingSuspend = false;
        $this->suspendCheck = false;
    }

    public function suspendAccess(SeasonService $season): void
    {
        // Accusé de réception explicite (motif §4.17) : la suspension annule les inscriptions futures
        // et fait remonter des tiers depuis la file, qui sont notifiés. Garde serveur.
        if (! $this->suspendCheck) {
            return;
        }

        $annulees = $season->suspendAthlete($this->user, auth()->user(), trim($this->suspendMotif) ?: null);

        $this->user->refresh();
        $this->confirmingSuspend = false;
        $this->suspendCheck = false;
        $this->suspendMotif = '';

        session()->flash('status', 'Accès athlète suspendu'
            .($annulees > 0 ? ' — '.$annulees.' inscription(s) future(s) annulée(s).' : '.'));
    }

    /** Réactive l'accès athlète suspendu (§4.4). Email transactionnel mis en file ; inscriptions annulées non restaurées. */
    public function reactivateAccess(SeasonService $season): void
    {
        $season->reactivateAthlete($this->user, auth()->user());
        $this->user->refresh();
        session()->flash('status', 'Accès athlète réactivé — email de réactivation mis en file.');
    }

    // --- Invitation d'activation (§4.1.3) ---

    /**
     * (Ré)envoie l'invitation d'activation. Régénère le jeton — le PRD veut le lien « régénérable »,
     * et l'ancien cesse d'être honoré : un lien qu'on croit remplacé ne doit pas rester ouvert.
     *
     * Throttle 3/h : un renvoi en rafale est du spam vers la boîte de l'adhérent, pas un dépannage.
     */
    public function sendInvitation(InvitationService $invitations): void
    {
        $key = 'member-invite|'.$this->user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            session()->flash('warn', 'Trop d’envois pour cet adhérent — réessaie plus tard.');

            return;
        }

        try {
            $invitations->sendToMember($this->user, auth()->user());
            RateLimiter::hit($key, 3600);
            $this->user->refresh();
            session()->flash('status', 'Invitation envoyée à '.$this->user->email.'.');
        } catch (RuntimeException $e) {
            session()->flash('warn', $e->getMessage());
        }
    }

    // --- Dépannage d'accès (§4.1.5) ---

    /**
     * Envoie à l'adhérent un lien de réinitialisation de mot de passe (TTL 15 min, §4.1.1).
     *
     * L'admin DÉCLENCHE, il ne connaît jamais le secret : pas de mot de passe temporaire, pas de
     * champ, pas d'affichage. C'est une propriété de sécurité, pas un détail d'implémentation —
     * détenir le facteur d'authentification d'un tiers rendrait l'usurpation possible et
     * indétectable. Le secret ne transite que par la boîte mail de l'adhérent.
     *
     * Envoi immédiat hors outbox (notification Laravel native), comme le magic link : l'adhérent
     * attend au bout du fil (cadrage §14.1, envois sensibles à la latence).
     */
    public function sendPasswordReset(): void
    {
        $u = $this->user;

        // Le mailer est forcé sur `log` en démo : le lien promis « dans ta boîte mail » n'arriverait
        // jamais. Même raisonnement que DemoMode::magicLinkUsable().
        if (DemoMode::enabled()) {
            session()->flash('warn', 'Indisponible en mode démo.');

            return;
        }

        // is_active couvre aussi la demande de suppression en cours (§4.3) : on n'aide pas un compte
        // à revenir alors que le tampon de 7 jours court.
        if ($u->email === null || ! $u->is_active || $u->anonymized_at !== null) {
            session()->flash('warn', 'Ce compte ne peut pas recevoir de lien de réinitialisation.');

            return;
        }

        $status = Password::broker()->sendResetLink(['email' => $u->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            session()->flash('warn', $status === Password::RESET_THROTTLED
                ? 'Un lien vient déjà d’être envoyé — patiente une minute.'
                : 'Envoi impossible pour ce compte.');

            return;
        }

        AuditLogger::record('password_reset_sent', auth()->user(), [
            'target_type' => User::class,
            'target_id' => $u->id,
        ]);

        session()->flash('status', 'Lien de réinitialisation envoyé à '.$u->email.'.');
    }

    // --- Suppression RGPD voie admin (§4.3) ---

    /** Voie 2 : confirme la demande après saisie exacte du nom complet (double validation §4.3). */
    public function requestDeletion(MemberService $service): void
    {
        if (trim($this->deleteConfirmName) !== trim($this->user->fullName())) {
            $this->addError('deleteConfirmName', 'Le nom saisi ne correspond pas au nom de l’adhérent.');

            return;
        }

        try {
            $service->requestDeletion($this->user, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('deleteConfirmName', $e->getMessage()); // ex. dernier admin actif

            return;
        }

        $this->reset('confirmingRequest', 'deleteConfirmName');
        $this->user->refresh();
        session()->flash('status', 'Demande de suppression enregistrée — tampon de 7 jours démarré.');
    }

    /** Annulation pendant le tampon : réactive le compte. */
    public function cancelDeletion(MemberService $service): void
    {
        $service->cancelDeletion($this->user, auth()->user());
        $this->user->refresh();
        session()->flash('status', 'Demande de suppression annulée — accès réactivé.');
    }

    /**
     * Confirmation définitive. Garde serveur (§4.3) redondante avec l'UI grisée : refuse avant J+7,
     * même si l'UI est contournée. Au succès → anonymisation + retour à la liste.
     */
    public function confirmDeletion(MemberService $service): void
    {
        if (! $this->user->isDeletionEligible()) {
            $this->confirmingFinal = false;
            $this->addError('deletion', 'Le tampon de 7 jours n’est pas écoulé : suppression impossible.');

            return;
        }

        $service->confirmDeletion($this->user, auth()->user());
        session()->flash('status', 'Compte supprimé et journaux anonymisés.');

        $this->redirectRoute('admin.members', navigate: true);
    }

    public function render()
    {
        // `wards.categories` et non `wards` seul : le bloc « Pupilles » affiche la catégorie d'âge de
        // CHAQUE enfant ($ward->primaryCategory(), qui lit $ward->categories). Sans l'imbrication, la
        // fiche d'un parent garant tombait sur « Attempted to lazy load [categories] » — la garde
        // preventLazyLoading rend l'oubli fatal, et il ne se voyait que sur les comptes à pupilles.
        $this->user->loadMissing(['categories', 'qualifications', 'guardian', 'wards.categories']);

        $primary = $this->user->primaryCategory();
        $surclassements = $this->user->surclassements();

        $availableCats = Category::query()->whereNull('archived_at')->orderBy('label')->get()
            ->reject(fn (Category $c) => $c->id === $primary?->id || $surclassements->contains('id', $c->id));

        $availableQuals = Qualification::query()->whereNull('archived_at')->orderBy('label')->get()
            ->reject(fn (Qualification $q) => $this->user->qualifications->contains('id', $q->id));

        // Historique d'inscriptions (§4.1.3) : futures / passées, avec stats.
        $registrations = $this->user->registrations()
            ->with(['session.discipline', 'session.location'])
            ->get()
            ->sortByDesc(fn ($r) => $r->session?->start_at);

        $now = Carbon::now();
        $future = $registrations->filter(fn ($r) => $r->session && $r->session->start_at && $r->session->start_at->isAfter($now))->values();
        $past = $registrations->filter(fn ($r) => $r->session && $r->session->start_at && ! $r->session->start_at->isAfter($now))->values();

        // Rattachement d'un garant (mineur sans tutelle) : adultes actifs candidats.
        $guardianCandidates = ($this->user->is_minor && $this->user->guardian_id === null && $this->user->anonymized_at === null)
            ? User::query()
                ->where('is_minor', false)
                ->where('is_active', true)
                ->whereNull('anonymized_at')
                ->whereKeyNot($this->user->id)
                ->orderBy('first_name')->orderBy('last_name')
                ->get()
            : collect();

        // Rattachement d'un pupille (vu du parent) : cet adhérent peut-il être garant, et quels
        // mineurs sans tutelle lui rattacher. Miroir de $guardianCandidates, du point de vue parent.
        $canBeGuardian = ! $this->user->is_minor && $this->user->is_active && $this->user->anonymized_at === null;
        $wardCandidates = $canBeGuardian
            ? User::query()
                ->where('is_minor', true)
                ->whereNull('guardian_id')
                ->whereNull('anonymized_at')
                ->whereKeyNot($this->user->id)
                ->orderBy('first_name')->orderBy('last_name')
                ->get()
            : collect();

        return view('livewire.admin.member-show', [
            'primary' => $primary,
            'surclassements' => $surclassements,
            'availableCats' => $availableCats,
            'availableQuals' => $availableQuals,
            'derivedCat' => $this->user->dob ? AgeCategory::derive($this->user->dob) : null,
            'future' => $future,
            'past' => $past,
            'guardianCandidates' => $guardianCandidates,
            // Pupilles (§4.2) : enfants dont cet adhérent est garant — carte visible côté parent.
            'wards' => $this->user->wards->whereNull('anonymized_at')->values(),
            'canBeGuardian' => $canBeGuardian,
            'wardCandidates' => $wardCandidates,
            // État d'activation (§4.1.3) : un compte est entré s'il s'est DÉJÀ CONNECTÉ, ou s'il a
            // posé un mot de passe, lié une identité OAuth, ou consommé une invitation. Le jeton
            // consommé sert de marqueur durable (cf. InvitationToken::prunable, qui ne l'élague
            // plus) ; last_login_at couvre le cas que les trois autres ratent — l'adhérent qui
            // n'entre que par lien magique, et qu'on affichait « jamais invité·e » à vie.
            'activated' => $this->user->last_login_at !== null
                || $this->user->password !== null
                || $this->user->authIdentities()->exists()
                || $this->user->invitations()->whereNotNull('consumed_at')->exists(),
            // Compteur d'impact de la suspension : ce que la modale annonce avant le clic.
            'futureRegs' => $this->user->registrations()
                ->whereIn('status', ['participating', 'waitlist'])
                ->whereHas('session', fn ($q) => $q->whereNull('cancelled_at')->where('start_at', '>', Carbon::now()))
                ->count(),
            'pendingInvite' => $this->user->invitations()
                ->whereNull('consumed_at')
                ->where('expires_at', '>', Carbon::now())
                ->latest('expires_at')
                ->first(),
        ]);
    }
}
