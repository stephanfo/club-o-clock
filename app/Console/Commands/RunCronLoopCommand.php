<?php

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Point d'entrée du planificateur sur hébergement mutualisé (INSTALL §5.4).
 *
 * Le mutualisé OVH ne déclenche un cron qu'**une fois par heure**, à une **minute imposée** par
 * l'hébergeur (champ « Minutes » désactivé au manager). Or Laravel attend `schedule:run` chaque
 * minute. Cette commande comble l'écart : lancée une fois par heure, elle appelle `schedule:run`
 * à chaque minute pendant `--duree`, puis rend la main avant que l'hébergeur ne coupe.
 *
 * Elle exploite le budget qu'OVH accorde par ailleurs : « La durée d'exécution d'une tâche est de
 * 60 minutes. » On s'arrête volontairement avant, pour laisser passer le cron suivant.
 *
 * ⚠️ Elle ne rend PAS les tâches à minute fixe fiables pour autant. Il reste un trou de
 * `60 - durée` minutes par heure, **et ce trou se déplace avec la minute imposée** : aucune minute
 * d'horloge n'est sûre. C'est pourquoi les tâches périodiques sont gardées par DuePeriodGuard et
 * planifiées fréquemment, plutôt que fixées à `hourly()` ou `dailyAt()` (cf. routes/console.php).
 *
 * Sur VPS, cette commande est inutile : une crontab classique appelle `schedule:run` chaque minute.
 */
class RunCronLoopCommand extends Command
{
    protected $signature = 'club:cron-boucle
        {--duree=55 : Durée de la boucle en minutes (doit rester < 60, coupure de l\'hébergeur)}';

    protected $description = 'Appelle schedule:run chaque minute pendant ~1 h, pour les hébergements dont le cron est horaire (§5.4).';

    /**
     * Marge avant l'échéance : au-delà, on ne démarre plus de passe.
     *
     * On ne peut pas prédire la durée d'une passe ; on peut en revanche garantir qu'on n'en
     * *commence* pas une trop tard. Ces secondes couvrent la fin du dernier fils, la libération
     * du verrou et la sortie propre, sous la coupure de l'hébergeur.
     */
    private const MARGE_SORTIE_SECONDES = 30;

    /** Durée maximale tolérée par l'hébergeur (OVH : « 3600 seconds »). */
    private const LIMITE_HEBERGEUR_MINUTES = 60;

    public function handle(SchedulerHeartbeatService $heartbeat): int
    {
        $duree = (int) $this->option('duree');

        // Refus net plutôt qu'un écrêtage silencieux : une durée >= 60 se ferait tuer en plein
        // vol par l'hébergeur, laissant un verrou orphelin et un rapport d'erreur quotidien.
        if ($duree < 1 || $duree >= self::LIMITE_HEBERGEUR_MINUTES) {
            $this->error(sprintf(
                'Refusé : --duree doit être comprise entre 1 et %d minutes (reçu : %d).',
                self::LIMITE_HEBERGEUR_MINUTES - 1,
                $duree,
            ));
            $this->line('  Au-delà, l\'hébergeur coupe le processus en cours d\'exécution.');

            return self::FAILURE;
        }

        // Le TTL doit dépasser la boucle (sinon le verrou saute en cours de route) mais rester
        // SOUS l'intervalle du cron : après un processus tué net, un verrou encore tenu ferait
        // rejeter l'exécution de l'heure suivante — deux heures d'arrêt au lieu d'une.
        $lock = Cache::lock('club:cron-boucle', ($duree * 60) + 120);

        if (! $lock->get()) {
            // Situation normale (boucle précédente encore en vie), pas une erreur : sortir en
            // succès. Un code non nul répété ferait désactiver la tâche par l'hébergeur.
            $this->line('Une boucle est déjà en cours : rien à faire.');

            return self::SUCCESS;
        }

        try {
            return $this->boucler($duree, $heartbeat);
        } finally {
            $lock->release();
        }
    }

    private function boucler(int $duree, SchedulerHeartbeatService $heartbeat): int
    {
        $echeance = Carbon::now()->addMinutes($duree);
        $dernierDepart = $echeance->copy()->subSeconds(self::MARGE_SORTIE_SECONDES);
        $passes = 0;
        $echecs = 0;

        // Première passe immédiate : la boucle démarre à la minute imposée par l'hébergeur, il
        // n'y a aucune raison d'attendre la minute suivante pour honorer les échéances en retard.
        while (Carbon::now()->lessThan($dernierDepart)) {
            $prochaine = Carbon::now()->startOfMinute()->addMinute();

            if (! $this->passe($echeance)) {
                $echecs++;
            }
            $passes++;

            // Cible recalculée à chaque tour, jamais `sleep(60)` après le travail : la durée des
            // passes s'accumulerait et la boucle dériverait jusqu'à sauter une minute entière.
            $this->dormirJusqua($prochaine, $dernierDepart);
        }

        $heartbeat->beat();

        $this->info(sprintf('Boucle terminée : %d passe(s), %d en échec.', $passes, $echecs));

        // Toujours SUCCESS quand la boucle a pu tourner, même si des passes ont échoué : OVH
        // désactive une tâche « après 10 tentatives d'exécution échouées ». Les échecs sont
        // tracés dans le journal applicatif et exposés à l'écran des envois, pas ici.
        return self::SUCCESS;
    }

    /** Lance une passe de `schedule:run` dans un processus fils. Retourne false si elle a échoué. */
    private function passe(Carbon $echeance): bool
    {
        // Fils séparé plutôt qu'un appel en processus : une tâche qui part en erreur fatale ou
        // qui fuit de la mémoire ne doit pas emporter la boucle avec elle.
        $process = Process::fromShellCommandline(
            PHP_BINARY.' artisan schedule:run --no-interaction',
            base_path(),
        );

        // Timeout borné par le temps restant : sans lui, un fils bloqué (endpoint distant qui ne
        // répond pas) maintiendrait le parent jusqu'à la coupure brutale de l'hébergeur.
        $restant = Carbon::now()->diffInSeconds($echeance, absolute: false);
        $process->setTimeout((float) max(1, $restant - 10));

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('Boucle cron : passe interrompue', ['exception' => $e->getMessage()]);

            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        // Tracer : sans cela, une tâche qui échoue à chaque passe resterait invisible derrière un
        // battement de cœur au vert — exactement la panne silencieuse que la supervision combat.
        Log::error('Boucle cron : échec de schedule:run', [
            'exit_code' => $process->getExitCode(),
            'stderr' => Str::limit($process->getErrorOutput(), 4000),
        ]);

        return false;
    }

    /** Dort jusqu'à $cible, sans jamais dépasser $limite. */
    private function dormirJusqua(Carbon $cible, Carbon $limite): void
    {
        $reveil = $cible->greaterThan($limite) ? $limite : $cible;
        $microsecondes = ($reveil->getTimestampMs() - Carbon::now()->getTimestampMs()) * 1000;

        if ($microsecondes > 0) {
            usleep((int) $microsecondes);
        }
    }
}
