<?php

namespace App\Console\Commands;

use App\Notifications\OutboxDrainer;
use Illuminate\Console\Command;

// Drain par lots de l'outbox notifications (cadrage §7.13/§7.14). Déclenché par le cron unique
// (schedule:run) ; envoie les lignes échues et reprogramme/abandonne les échecs (retry/backoff).
class DrainNotificationsCommand extends Command
{
    protected $signature = 'notifications:drain {--limit=100 : Nombre maximum de lignes traitées par passe}';

    protected $description = 'Vide par lots la file d\'envois (outbox) push/email avec retry/backoff (§7.14).';

    public function handle(OutboxDrainer $drainer): int
    {
        $stats = $drainer->drainDue((int) $this->option('limit'));

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
