<?php

namespace App\Console\Commands;

use App\Notifications\OutboxDrainer;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

// Drain par lots de l'outbox notifications (cadrage §7.13/§7.14). Déclenché par le cron unique
// (schedule:run) ; envoie les lignes échues et reprogramme/abandonne les échecs (retry/backoff).
class DrainNotificationsCommand extends Command
{
    protected $signature = 'notifications:drain {--limit=100 : Nombre maximum de lignes traitées par passe}';

    protected $description = 'Vide par lots la file d\'envois (outbox) push/email avec retry/backoff (§7.14).';

    public function handle(OutboxDrainer $drainer, SchedulerHeartbeatService $heartbeat): int
    {
        $stats = $drainer->drainDue((int) $this->option('limit'));

        // Battement de cœur du cron : marqué à CHAQUE passe, même sans rien à envoyer — c'est
        // précisément une file vide qui rend une panne indétectable autrement. Posé ici plutôt que
        // dans OutboxDrainer, que le drain à la demande (bouton admin) emprunte aussi : un clic
        // humain ne doit pas faire croire que le cron tourne.
        $heartbeat->beat();

        $this->info(sprintf(
            'Outbox drainée : %d envoyée(s), %d reprogrammée(s), %d en échec, %d annulée(s).',
            $stats['sent'],
            $stats['retried'],
            $stats['failed'],
            $stats['cancelled'],
        ));

        return self::SUCCESS;
    }
}
