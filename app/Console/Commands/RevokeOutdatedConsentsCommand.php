<?php

namespace App\Console\Commands;

use App\Services\ConsentService;
use App\Support\Logging\AuditLogger;
use App\Support\Trombi\ConsentType;
use Illuminate\Console\Command;

/**
 * Changer le texte d'un accord du trombinoscope (cadrage §7.18) : incrémenter la version de son
 * type dans ConsentType, déployer, PUIS lancer cette commande. Elle révoque les accords donnés sur
 * l'ancienne version — déjà inactifs, ils restaient « non révoqués » au registre — pour que le
 * registre dise ce qui s'affiche. Les données restent, masquées, au profil de chacun.
 *
 * Sans incrément préalable, aucun accord ne porte une autre version : la commande ne fait rien.
 */
class RevokeOutdatedConsentsCommand extends Command
{
    protected $signature = 'club:trombi-revoquer {type : trombi_full_name, trombi_photo, …}';

    protected $description = 'Révoque les accords du trombinoscope donnés sur une version antérieure du texte.';

    public function handle(ConsentService $consents): int
    {
        $type = ConsentType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->error('Type inconnu. Types : '.ConsentService::typeList(ConsentType::cases()).'.');

            return self::FAILURE;
        }

        $count = $consents->revokeOutdated($type);
        AuditLogger::record('consents_outdated_revoked', null, [
            'motif' => sprintf('%s v%d · %d accord(s) révoqué(s)', $type->value, $type->version(), $count),
        ]);
        $this->info(sprintf('%d accord(s) « %s » antérieurs à la version %d révoqué(s).', $count, $type->label(), $type->version()));

        return self::SUCCESS;
    }
}
