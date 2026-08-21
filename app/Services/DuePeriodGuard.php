<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Garde d'échéance des tâches périodiques rattrapables (INSTALL §5.4).
 *
 * Sur hébergement mutualisé, le cron ne se déclenche qu'une fois par heure, à une minute imposée
 * par l'hébergeur. Le planificateur est donc lancé par une boucle (`club:cron-boucle`) qui couvre
 * ~55 min sur 60 : il reste un trou de quelques minutes, **et ce trou se déplace avec la minute
 * imposée**. Aucune minute d'horloge n'est donc sûre — une tâche planifiée à `hourly()`
 * (soit `0 * * * *`) peut n'être vue *jamais*, silencieusement, selon l'heure de lancement.
 *
 * D'où le renversement : une tâche périodique n'est plus déclenchée par l'observation d'une minute
 * précise, mais planifiée **fréquemment** (`everyFiveMinutes`) et gardée par cette classe, qui
 * répond « l'échéance de la période courante est-elle déjà honorée ? ». La sémantique passe de
 * « seulement si le scheduler passe pile à la bonne minute » à **« au moins une fois après
 * l'échéance, une seule fois effectivement »**.
 *
 * Effet de bord bienvenu : une passe manquée pour une tout autre raison (boucle tuée par un quota,
 * redémarrage) est rattrapée à la passe suivante, au lieu d'être perdue jusqu'à la période d'après.
 *
 * Stockage en cache (driver `database`) et non en table dédiée : la donnée est purement
 * opérationnelle et n'a pas à peser une migration. Un `cache:clear` autorise une exécution
 * supplémentaire — sans dommage, les commandes gardées étant idempotentes par ailleurs.
 */
class DuePeriodGuard
{
    /** Conservé bien au-delà de la plus longue période gardée (quotidienne). */
    private const TTL_DAYS = 7;

    /**
     * Exécute $work si la période courante n'a pas encore été honorée.
     *
     * @param  string  $task  Identifiant de tâche, ex. « weather-refresh »
     * @param  string  $period  Clé de période, ex. « 2026-08-21T15 » (horaire) ou « 2026-08-21 »
     * @param  callable():bool  $work  Retourne true si le travail a réussi (seul un succès est enregistré)
     * @return bool true si $work a été exécuté avec succès, false si l'échéance était déjà honorée
     *              ou si le verrou était tenu par une autre passe
     */
    public function runIfDue(string $task, string $period, callable $work): bool
    {
        $key = $this->key($task, $period);

        // Verrou d'abord : le test de l'échéance et l'enregistrement du succès doivent être
        // atomiques, sinon deux passes concurrentes (boucle relancée, drain à la demande)
        // exécutent le travail deux fois. `withoutOverlapping()` du planificateur ne suffit
        // pas — il ignore la notion de période.
        //
        // Verrou court, et non bloquant : passer son tour est sans conséquence, la passe
        // suivante réessaiera dans 5 min. Attendre bloquerait la boucle pour rien.
        $lock = Cache::lock($key.':lock', 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            if (Cache::has($key)) {
                return false;
            }

            if ($work() !== true) {
                // Échec : on n'enregistre rien, la passe suivante réessaiera. C'est précisément
                // ce qui distingue « rattrapable » de « planifié à une minute fixe ».
                return false;
            }

            Cache::put($key, Carbon::now()->toIso8601String(), Carbon::now()->addDays(self::TTL_DAYS));

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Horodatage du dernier succès enregistré pour cette période, ou null. */
    public function lastSuccessAt(string $task, string $period): ?Carbon
    {
        $raw = Cache::get($this->key($task, $period));

        return is_string($raw) ? Carbon::parse($raw) : null;
    }

    /** Clé de période horaire (UTC) : une échéance par heure d'horloge. */
    public static function hourlyPeriod(?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())->format('Y-m-d\TH');
    }

    /** Clé de période quotidienne, dans le fuseau donné (l'échéance « du jour » est locale). */
    public static function dailyPeriod(?Carbon $at = null, string $timezone = 'UTC'): string
    {
        return ($at ?? Carbon::now())->copy()->setTimezone($timezone)->format('Y-m-d');
    }

    private function key(string $task, string $period): string
    {
        return "due.{$task}.{$period}";
    }
}
