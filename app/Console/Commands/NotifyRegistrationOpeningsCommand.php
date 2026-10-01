<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\SessionNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Notification d'ouverture des inscriptions aux compétitions (#105, PRD §4.15.2).
 *
 * Pas de ligne d'envoi programmée à l'avance : la date d'ouverture change souvent (connue tard,
 * corrigée), et une file à replanifier serait une source de doublons. La commande passe toutes
 * les 5 min, juste avant le drain, et émet ce qui est arrivé à échéance : 15 min avant l'heure
 * connue, 9 h heure club sinon (Session::registrationOpeningNotifyAt). Elle arrive donc entre
 * H-15 et H-10.
 *
 * Unicité : `registration_opening_notified_at`, posé par une mise à jour conditionnelle AVANT
 * l'envoi — deux passes concurrentes ne peuvent pas l'obtenir toutes les deux. Le formulaire le
 * remet à null quand la date change vers le futur : c'est la replanification.
 *
 * Pas d'envoi en retard : une échéance dont l'ouverture est déjà passée (course réactivée après
 * coup, cron arrêté une journée) est marquée sans envoi — « ouvrent aujourd'hui » serait faux.
 * Une compétition annulée n'est jamais notifiée.
 */
class NotifyRegistrationOpeningsCommand extends Command
{
    protected $signature = 'notifications:ouvertures';

    protected $description = 'Émet les notifications d\'ouverture des inscriptions arrivées à échéance.';

    public function handle(SessionNotificationService $notifier): int
    {
        $now = Carbon::now();

        // Large présélection SQL (l'instant d'envoi dépend du drapeau d'heure) : ouverture entre
        // hier et dans 15 min. Le tri fin se fait en PHP.
        $candidates = Session::query()
            ->where('kind', 'competition')
            ->whereNull('cancelled_at')
            ->whereNull('registration_opening_notified_at')
            ->whereNotNull('registration_opens_at')
            ->where('registration_opens_at', '<=', $now->copy()->addMinutes(Session::OPENING_NOTIFY_LEAD_MIN)->utc())
            ->where('registration_opens_at', '>=', $now->copy()->subDays(2)->utc())
            ->get();

        $sent = 0;
        foreach ($candidates as $session) {
            $due = $session->registrationOpeningNotifyAt();
            if ($due === null || $due->gt($now)) {
                continue;
            }

            $claimed = Session::query()
                ->whereKey($session->id)
                ->whereNull('registration_opening_notified_at')
                ->update(['registration_opening_notified_at' => $now->copy()->utc()]);
            if ($claimed !== 1 || ! $this->stillRelevant($session, $now)) {
                continue;
            }

            $notifier->notifyRegistrationOpening($session);
            $sent++;
        }

        $this->info("{$sent} notification(s) d'ouverture émise(s).");

        return self::SUCCESS;
    }

    /** L'ouverture n'est pas encore passée : à l'heure dite, ou dans la journée sans heure connue. */
    private function stillRelevant(Session $session, Carbon $now): bool
    {
        $opens = $session->registrationOpensLocal();
        $limit = $session->registration_opens_has_time ? $opens : $opens->copy()->endOfDay();

        return $now->lte($limit) && ! $session->hasStarted();
    }
}
