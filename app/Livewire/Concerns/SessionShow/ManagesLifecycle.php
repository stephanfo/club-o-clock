<?php

namespace App\Livewire\Concerns\SessionShow;

use App\Notifications\NotificationType;
use App\Services\AperoService;
use App\Services\RegistrationService;
use App\Services\SessionNotificationService;
use App\Support\Logging\AuditLogger;
use Illuminate\Support\Carbon;

// Cycle de vie de la séance : annulation / restauration (PRD §4.7) — actions coach/admin.
trait ManagesLifecycle
{
    /** Dialog de confirmation d'annulation ouvert ? (revue UX 2026-07-11 : action structurante
     *  → dialog stylé avec conséquences, pas un confirm() natif). */
    public bool $confirmingCancel = false;

    public function openCancelConfirm(): void
    {
        $this->authorize('cancel', $this->session);
        $this->confirmingCancel = true;
    }

    public function dismissCancelConfirm(): void
    {
        $this->confirmingCancel = false;
    }

    public function cancel(RegistrationService $service, AperoService $apero, SessionNotificationService $notifier): void
    {
        $this->authorize('cancel', $this->session);
        $this->confirmingCancel = false;

        if ($this->session->isCancelled()) {
            return;
        }

        // Annulation = soft flag (§4.7). Le flag est posé AVANT la cascade quota : une séance
        // annulée ne consomme plus de quota → onSessionCancelled déclenche le mécanisme B (§4.10.6).
        $this->session->forceFill([
            'cancelled_at' => Carbon::now(),
            'cancelled_by' => auth()->id(),
        ])->save();

        $service->onSessionCancelled($this->session);

        // §4.14.4 : tous les flags apéro sont garés (réversible à la restauration).
        $apero->cascadeOnSessionCancel($this->session);

        AuditLogger::record('cancel_session', auth()->user(), ['session_id' => $this->session->id]);

        // Notif aux inscrits TOUJOURS envoyée (événement trop structurant, §4.7) — après commit.
        $notifier->notifyParticipants($this->session, NotificationType::SessionCancelled);

        $this->refreshSession();
        session()->flash('status', 'Séance annulée.');
    }

    public function restore(AperoService $apero, SessionNotificationService $notifier): void
    {
        $this->authorize('restore', $this->session); // inclut le garde-fou startAt futur

        if (! $this->session->isCancelled()) {
            return;
        }

        $this->session->forceFill(['cancelled_at' => null, 'cancelled_by' => null])->save();

        // §4.14.4 : restaure les flags apéro garés dont l'inscription est toujours active.
        $apero->restoreOnSessionUncancel($this->session);

        AuditLogger::record('restore_session', auth()->user(), ['session_id' => $this->session->id]);

        // Notif « la séance est réactivée, ton inscription est rétablie » (§4.7) — après commit.
        $notifier->notifyParticipants($this->session, NotificationType::SessionRestored);

        // refreshSession() et non refresh() : refresh() recharge les relations par leur nom de
        // premier niveau et perd les imbrications (registrations.user, coaches.qualifications).
        $this->refreshSession();
        session()->flash('status', 'Séance réactivée.');
    }
}
