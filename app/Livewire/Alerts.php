<?php

namespace App\Livewire;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
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

        // Résoudre les session_id en séances (batch). On lit la base plutôt que le titre figé au
        // payload : cet écran se consulte longtemps après l'envoi, une séance renommée depuis doit
        // s'y voir sous son nom actuel. Le payload sert de repli quand la séance a été supprimée.
        $sessionIds = $outboxItems->pluck('payload')->map(fn ($p) => $p['session_id'] ?? null)->filter()->unique()->values()->all();
        $sessions = Session::whereIn('id', $sessionIds)->get()->keyBy('id');

        // Prénom du sujet : re-résolu depuis subject_id, car le prénom figé au payload est purgé à
        // l'envoi (NotificationOutbox::VOLATILE_PAYLOAD_KEYS) et ces lignes sont toutes `sent`.
        $subjectIds = $outboxItems->pluck('payload')->map(fn ($p) => $p['subject_id'] ?? null)->filter()->unique()->values()->all();
        $subjects = User::whereIn('id', $subjectIds)->pluck('first_name', 'id');

        $tz = ClubSettings::current()->timezone;

        $alerts = $outboxItems->map(function (NotificationOutbox $row) use ($sessions, $subjects, $tz) {
            $type = NotificationType::tryFrom($row->type);
            $sessionId = $row->payload['session_id'] ?? null;
            $session = $sessionId ? ($sessions[$sessionId] ?? null) : null;

            $label = $type?->label() ?? $row->type;
            // Sujet ≠ destinataire : notification qui concerne un enfant (§4.15.5). Même composition
            // que le push, pour qu'une alerte se reconnaisse d'un écran à l'autre.
            $subjectId = $row->payload['subject_id'] ?? null;
            $prenom = ($subjectId !== null && $subjectId !== $row->user_id)
                ? ($subjects[$subjectId] ?? null)
                : null;

            return [
                'title' => $prenom !== null ? $prenom.' · '.$label : $label,
                'sub' => self::sousTitre($session, $row->payload ?? [], $tz),
                'when' => $row->sent_at?->diffForHumans() ?? '',
                'icon' => self::iconFor($type),
                'tintBg' => self::tintBgFor($type),
                'tintFg' => self::tintFgFor($type),
                'sessionId' => $sessionId,
            ];
        });

        return view('livewire.alerts', ['alerts' => $alerts]);
    }

    /**
     * Sous-titre d'une alerte : la séance et son créneau, à l'heure du club. Le titre vient de la
     * base (frais) ; le payload prend le relais si la séance a été supprimée depuis — sans lui, une
     * alerte d'annulation devenait une ligne muette au moment où elle sert encore.
     *
     * @param  array<string,mixed>  $payload
     */
    private static function sousTitre(?Session $session, array $payload, string $tz): ?string
    {
        $titre = $session->title ?? ($payload['session_title'] ?? null);

        if ($titre === null) {
            return null;
        }

        if ($session?->start_at === null) {
            return $titre;
        }

        $start = $session->start_at->copy()->setTimezone($tz)->locale('fr');

        return $titre.' · '.$start->isoFormat('ddd D MMM').' · '.$start->format('H:i');
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
