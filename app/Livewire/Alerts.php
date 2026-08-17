<?php

namespace App\Livewire;

use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Notifications\NotificationType;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

// Page Alertes — liste les notifications push envoyées à l'utilisateur courant (outbox sent).
// Source de vérité : notification_outbox (status=sent, channel=push), 60 derniers jours.
// État lu/non-lu (revue UX 2026-07-11) : ouvrir la page marque tout lu → le badge (cloche,
// nav) retombe à zéro. Le marquage est en bloc, pas par ligne (geste unique, pas de gestion).
#[Layout('layouts.app')]
#[Title('Alertes')]
class Alerts extends Component
{
    public function mount(): void
    {
        NotificationOutbox::alertsFor(auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        // Le badge (sidebar + cloche) est rendu après ce mount : invalider le cache par requête
        // pour qu'il reflète le marquage lu qu'on vient d'appliquer.
        NotificationOutbox::forgetUnreadCount(auth()->id());
    }

    public function render()
    {
        $outboxItems = NotificationOutbox::alertsFor(auth()->id())
            ->orderByDesc('sent_at')
            ->limit(50)
            ->get();

        // Résoudre les session_id en titres de séance (batch).
        $sessionIds = $outboxItems->pluck('payload')->map(fn ($p) => $p['session_id'] ?? null)->filter()->unique()->values()->all();
        $sessions = Session::whereIn('id', $sessionIds)->pluck('title', 'id');

        $alerts = $outboxItems->map(function (NotificationOutbox $row) use ($sessions) {
            $type = NotificationType::tryFrom($row->type);
            $sessionId = $row->payload['session_id'] ?? null;
            $sessionTitle = $sessionId ? ($sessions[$sessionId] ?? null) : null;

            return [
                'title' => $type?->label() ?? $row->type,
                'sub' => $sessionTitle,
                'when' => $row->sent_at?->diffForHumans() ?? '',
                'icon' => self::iconFor($type),
                'tintBg' => self::tintBgFor($type),
                'tintFg' => self::tintFgFor($type),
                'sessionId' => $sessionId,
            ];
        });

        return view('livewire.alerts', ['alerts' => $alerts]);
    }

    private static function iconFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted => 'check',
            NotificationType::SessionCancelled => 'x',
            NotificationType::SessionRestored => 'rotate-ccw',
            NotificationType::EnrolledByCoach, NotificationType::CoachOverride => 'user-check',
            NotificationType::SessionModified, NotificationType::SessionContent => 'pen-line',
            NotificationType::NewDebrief => 'pen-line',
            NotificationType::CoachAssigned, NotificationType::CoachRegistration => 'user-check',
            NotificationType::EventCreated => 'calendar',
            default => 'bell',
        };
    }

    private static function tintBgFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted, NotificationType::EnrolledByCoach,
            NotificationType::CoachOverride, NotificationType::CoachAssigned => 'var(--brand-50)',
            NotificationType::SessionCancelled => 'var(--accent-50)',
            NotificationType::SessionRestored => 'var(--brand-50)',
            NotificationType::SessionModified, NotificationType::SessionContent,
            NotificationType::EventCreated => 'var(--info-50)',
            default => 'var(--slate-100)',
        };
    }

    private static function tintFgFor(?NotificationType $t): string
    {
        return match ($t) {
            NotificationType::WaitlistPromoted, NotificationType::EnrolledByCoach,
            NotificationType::CoachOverride, NotificationType::CoachAssigned,
            NotificationType::SessionRestored => 'var(--brand-700)',
            NotificationType::SessionCancelled => 'var(--accent-700)',
            NotificationType::SessionModified, NotificationType::SessionContent,
            NotificationType::EventCreated => 'var(--info-700)',
            default => 'var(--slate-700)',
        };
    }
}
