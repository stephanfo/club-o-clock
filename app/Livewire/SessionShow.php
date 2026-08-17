<?php

namespace App\Livewire;

use App\Livewire\Concerns\SessionShow\ManagesApero;
use App\Livewire\Concerns\SessionShow\ManagesCoaching;
use App\Livewire\Concerns\SessionShow\ManagesDebriefs;
use App\Livewire\Concerns\SessionShow\ManagesEnrollment;
use App\Livewire\Concerns\SessionShow\ManagesLifecycle;
use App\Livewire\Concerns\WithSubject;
use App\Models\ClubSettings;
use App\Models\Debrief;
use App\Models\Session;
use App\Services\RegistrationService;
use App\Services\WeatherService;
use App\Support\QualificationDisplay;
use App\Support\RegistrantDisplay;
use App\Support\SubjectContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Fiche séance (PRD §4.7-§4.14). Le composant porte le montage, le rendu et les helpers
// transverses ; les actions vivent dans des traits par domaine (Concerns/SessionShow/) :
// cycle de vie (annuler/restaurer), inscriptions (self + bureau + quotas), encadrement coach,
// apéro, débriefs. L'inscription self-service suit le sujet consulté (parent → enfant, §4.2).
#[Layout('layouts.app')]
#[Title('Séance')]
class SessionShow extends Component
{
    use ManagesApero;
    use ManagesCoaching;
    use ManagesDebriefs;
    use ManagesEnrollment;
    use ManagesLifecycle;
    use WithSubject;

    public Session $session;

    public function mount(Session $session): void
    {
        // §4.2 « Mes enfants » : le lien porte le sujet enfant (?as=). On le pose côté serveur DANS
        // cette requête GET, avant le rendu → pas de course avec wire:navigate. set() valide le ward
        // (ignore silencieusement un id non garanti), donc lire ?as= en clair est sûr.
        // Puis redirection vers l'URL canonique (sans ?as=) : sinon reload / back-forward
        // re-basculeraient le sujet global sans geste délibéré (?as= ne doit vivre qu'un clic).
        if (auth()->check() && ($as = request()->integer('as'))) {
            SubjectContext::set(auth()->user(), $as);
            $this->redirect(route('sessions.show', $session), navigate: true);

            return;
        }

        $this->authorize('view', $session);
        $this->session = $session->load(self::EAGER);
    }

    /** Relations de la fiche — même jeu au mount, au refresh et à la ré-hydratation. */
    private const EAGER = ['discipline', 'eventType', 'quotaTag', 'location', 'creator', 'coaches.qualifications', 'categories', 'registrations.user', 'activeAperoFlags.user', 'debriefs.author', 'debriefs.archiver', 'gpxRoute.discipline'];

    private function tz(): string
    {
        return ClubSettings::current()->timezone;
    }

    private function refreshSession(): void
    {
        $this->session->load(self::EAGER);
    }

    /** Traduit les sentinelles d'inscription en message lisible (sinon renvoie le message tel quel). */
    private function translateRegError(string $message): string
    {
        return RegistrationService::userMessage($message);
    }

    /**
     * État + données météo de la fiche (§4.13.5). Lecture cache-or-fetch bornée ; ne lève jamais.
     * États : none (passée/annulée) · nogeo (lieu non géocodé) · far (> J-16) · full · pending.
     *
     * @return array{state:string, data:?array}
     */
    private function weatherData(): array
    {
        $s = $this->session;
        if ($s->isCancelled() || $s->hasStarted()) {
            return ['state' => 'none', 'data' => null];
        }

        $lat = $s->location?->latitude;
        $lng = $s->location?->longitude;
        if ($lat === null || $lng === null) {
            return ['state' => 'nogeo', 'data' => null];
        }

        $service = app(WeatherService::class);
        if (! $service->inWindow($s->start_at)) {
            return ['state' => 'far', 'data' => null];
        }

        $forecast = $service->forecast((float) $lat, (float) $lng, $s->start_at);

        return ['state' => $forecast ? 'full' : 'pending', 'data' => $forecast];
    }

    /** Conflit horaire : le SUJET est déjà inscrit à une séance qui chevauche celle-ci (§4.9.6). */
    private function hasScheduleConflict(): bool
    {
        $user = auth()->check() ? $this->subject() : null;
        if (! $user) {
            return false;
        }

        $start = $this->session->start_at;
        $end = $start->copy()->addMinutes($this->session->duration_min);

        // Comparaison des bornes en PHP (portable cross-DB ; volume par utilisateur faible).
        return Session::query()
            ->where('id', '!=', $this->session->id)
            ->whereNull('cancelled_at')
            ->where('start_at', '<', $end)
            ->whereHas('registrations', fn ($q) => $q->where('user_id', $user->id)
                ->whereIn('status', ['participating', 'waitlist']))
            ->get(['id', 'start_at', 'duration_min'])
            ->contains(fn (Session $other) => $other->start_at->copy()->addMinutes($other->duration_min)->gt($start));
    }

    /**
     * Table [user_id => libellé] des inscrits selon le viewer (§4.9.4) :
     * nom complet pour coach/admin et coachs encadrants, prénom + initiale entre athlètes.
     *
     * @return array<int, string>
     */
    private function nameLabels(): array
    {
        $viewer = auth()->user();
        $fullNames = $viewer !== null && (
            $viewer->hasRole('coach') || $viewer->hasRole('admin')
            || $this->session->coaches->contains('id', $viewer->id)
        );

        $users = $this->session->registrations
            ->whereIn('status', ['participating', 'waitlist'])
            ->map(fn ($r) => $r->user);

        return RegistrantDisplay::labels($users, $fullNames);
    }

    public function render()
    {
        // La ré-hydratation Livewire (requêtes suivantes) recharge le modèle mais perd les
        // relations IMBRIQUÉES (coaches.qualifications, registrations.user…). loadMissing ne
        // recharge que les segments absents — gratuit quand mount()/refreshSession() a tout posé.
        $this->session->loadMissing(self::EAGER);

        $me = auth()->user();
        $isCoachMember = $me !== null && ($me->hasRole('coach') || $me->hasRole('admin'));

        // Apéro (§4.14) : liste des payeurs actifs (FIFO), mon propre flag, droit de flagger.
        $aperoPayers = $this->session->activeAperoFlags->sortBy('flagged_at')->values();
        $myReg = $me ? $this->session->registrations->firstWhere('user_id', $me->id) : null;
        $iParticipate = $myReg !== null && $myReg->status === 'participating';

        // Débriefs (§4.12.5) : libellés auteurs selon le viewer (§4.9.4), comme les inscrits.
        $fullNames = $me !== null && ($me->hasRole('coach') || $me->hasRole('admin')
            || $this->session->coaches->contains('id', $me->id));
        $debriefLabels = RegistrantDisplay::labels(
            $this->session->debriefs->map(fn (Debrief $d) => $d->author)->filter(),
            $fullNames
        );

        $weather = $this->weatherData();

        return view('livewire.session-show', [
            'tz' => $this->tz(),
            'hasConflict' => $this->hasScheduleConflict(),
            'nameLabels' => $this->nameLabels(),
            'aperoPayers' => $aperoPayers,
            'iAmAperoPayer' => $me !== null && $aperoPayers->contains('user_id', $me->id),
            // Le bouton « J'offre » n'apparaît qu'aux inscrits actifs, séance ouverte (§4.14.1/.3).
            'canFlagApero' => $iParticipate && ! $this->session->hasStarted() && ! $this->session->isCancelled(),
            // Encadrement (§4.11.4) : qualifs agrégées dédupliquées sur les coachs inscrits.
            'aggregatedQualifs' => QualificationDisplay::aggregate($this->session->coaches),
            // Le viewer (coach/admin) peut gérer l'encadrement, et l'est-il déjà lui-même ?
            'canManageCoaches' => $isCoachMember,
            'iAmCoachHere' => $me !== null && $this->session->coaches->contains('id', $me->id),
            'selectableCoaches' => $this->pickingCoach ? $this->selectableCoaches() : collect(),
            // Inscription/retrait d'un athlète par le bureau (§4.9.7) — le bureau gère, séance ouverte.
            'isStaff' => $isCoachMember,
            'canEnrollOther' => $isCoachMember && ! $this->session->hasStarted() && ! $this->session->isCancelled(),
            'selectableAthletes' => $this->pickingAthlete ? $this->selectableAthletes() : collect(),
            // Débriefs (§4.12.5).
            'debriefLabels' => $debriefLabels,
            'canWriteDebrief' => $this->canWriteDebrief(),
            'debriefInitialMarkdown' => $this->debriefOpen ? $this->debriefMarkdown : '',
            // Météo (§4.13.5).
            'weatherState' => $weather['state'],
            'weather' => $weather['data'],
            // Sujet consulté (parent → enfant, §4.2) : pilote l'inscription self-service.
            ...($me ? $this->subjectViewData() : []),
        ]);
    }
}
