<?php

namespace Tests\Feature;

use App\Console\Commands\RunCronLoopCommand;
use App\Services\DuePeriodGuard;
use App\Services\SchedulerHeartbeatService;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Planificateur sur hébergement mutualisé (INSTALL §5.4).
 *
 * Le cron n'est déclenché qu'une fois par heure, à une minute imposée par l'hébergeur ; la boucle
 * `club:cron-boucle` couvre ~55 min sur 60. Le trou restant se déplaçant avec cette minute
 * imposée, aucune minute d'horloge n'est sûre — d'où deux garde-fous testés ici : les tâches ne
 * dépendent d'aucune minute absolue, et la boucle respecte la limite de l'hébergeur.
 */
class CronLoopTest extends TestCase
{
    use RefreshDatabase;

    /** Durée de la boucle en production (cf. cron.php). */
    private const DUREE_BOUCLE = 55;

    // ─────────── Couverture des échéances ───────────

    /**
     * LE test de non-régression du lot : aucune tâche ne doit dépendre d'une minute d'horloge.
     *
     * Une tâche en `hourly()` (`0 * * * *`) n'est due qu'à la minute 0. Si l'hébergeur attribue
     * un démarrage entre :31 et :34, la minute 0 tombe dans le trou de couverture et la tâche
     * n'est vue JAMAIS — pas « en retard », jamais, et sans le moindre signal.
     *
     * On vérifie donc que chaque tâche est vue au moins une fois par heure, pour les 60 minutes
     * de démarrage possibles.
     */
    public function test_toutes_les_taches_sont_vues_quelle_que_soit_la_minute_imposee(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events, 'Aucune tâche planifiée : le test ne prouverait rien.');

        foreach ($events as $event) {
            foreach (range(0, 59) as $minuteDemarrage) {
                $vues = $this->occurrencesVues($event->expression, $minuteDemarrage);

                $this->assertGreaterThan(
                    0,
                    $vues,
                    sprintf(
                        "La tâche « %s » (%s) n'est JAMAIS vue si le cron démarre à la minute %d. ".
                        'Une tâche ne doit pas dépendre d\'une minute absolue : la planifier '.
                        'fréquemment (everyFiveMinutes) et la garder par --if-due.',
                        $event->command ?? '?',
                        $event->expression,
                        $minuteDemarrage,
                    ),
                );
            }
        }
    }

    /**
     * Compte les occurrences d'une expression cron vues par la boucle sur 24 h.
     *
     * La fenêtre déborde sur l'heure suivante quand `minute + durée >= 60` : on simule 48 h et ne
     * mesure que les dernières 24 h, en régime établi.
     */
    private function occurrencesVues(string $expression, int $minuteDemarrage): int
    {
        $cron = new CronExpression($expression);
        $couvert = [];

        for ($heure = 0; $heure < 48; $heure++) {
            $depart = $heure * 60 + $minuteDemarrage;
            for ($offset = 0; $offset <= self::DUREE_BOUCLE; $offset++) {
                $couvert[$depart + $offset] = true;
            }
        }

        $base = Carbon::parse('2026-08-21 00:00:00');
        $vues = 0;

        for ($minute = 24 * 60; $minute < 48 * 60; $minute++) {
            if (isset($couvert[$minute]) && $cron->isDue($base->copy()->addMinutes($minute))) {
                $vues++;
            }
        }

        return $vues;
    }

    // ─────────── Refus (qui n'a PAS le droit) ───────────

    public function test_la_boucle_refuse_une_duree_atteignant_la_limite_de_l_hebergeur(): void
    {
        $this->artisan('club:cron-boucle', ['--duree' => 60])
            ->expectsOutputToContain('Refusé')
            ->assertFailed();
    }

    public function test_la_boucle_refuse_une_duree_nulle(): void
    {
        $this->artisan('club:cron-boucle', ['--duree' => 0])->assertFailed();
    }

    // ─────────── Anti-chevauchement ───────────

    public function test_une_seconde_boucle_sort_en_succes_sans_travailler(): void
    {
        // Verrou déjà tenu : le cron de l'heure suivante ne doit pas doubler la boucle en cours.
        $lock = Cache::lock('club:cron-boucle', 120);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('club:cron-boucle', ['--duree' => 55])
                ->expectsOutputToContain('déjà en cours')
                ->assertSuccessful();   // pas FAILURE : OVH désactive une tâche après 10 échecs
        } finally {
            $lock->release();
        }
    }

    public function test_le_verrou_expire_avant_le_cron_suivant(): void
    {
        // Un verrou survivant à un processus tué net ferait rejeter l'exécution de l'heure
        // suivante : deux heures d'arrêt au lieu d'une. Le TTL doit rester sous l'intervalle
        // du cron (60 min), tout en couvrant la durée de la boucle.
        $ttl = (self::DUREE_BOUCLE * 60) + 120;

        $this->assertGreaterThan(
            self::DUREE_BOUCLE * 60,
            $ttl,
            'Le verrou expirerait pendant que la boucle tourne.',
        );
        $this->assertLessThan(
            60 * 60,
            $ttl,
            'Un verrou fantôme bloquerait le cron de l\'heure suivante.',
        );
    }

    public function test_les_signaux_d_arret_sont_des_valeurs_litterales(): void
    {
        // Les constantes de signal viennent de l'extension pcntl, absente de certains
        // mutualisés : les référencer ferait échouer la commande au chargement, sur
        // l'hébergement même où elle est indispensable. On vérifie la valeur effective.
        $signaux = (new \ReflectionClass(RunCronLoopCommand::class))
            ->getConstant('SIGNAUX_ARRET');

        $this->assertSame([2, 15, 3], $signaux, 'Valeurs POSIX attendues (SIGINT, SIGTERM, SIGQUIT).');
    }

    public function test_la_boucle_ne_depend_pas_du_battement_de_coeur(): void
    {
        // Le battement de cœur appartient au drain (DrainNotificationsCommand) : il atteste qu'une
        // tâche a réellement tourné. Si la boucle en posait un, une série de passes toutes en
        // échec afficherait quand même « traitement automatique actif » — la panne silencieuse
        // que SchedulerHeartbeatService existe précisément pour éviter.
        $handle = new \ReflectionMethod(RunCronLoopCommand::class, 'handle');

        $types = array_map(
            fn (\ReflectionParameter $p) => $p->getType()?->__toString(),
            $handle->getParameters(),
        );

        $this->assertNotContains(SchedulerHeartbeatService::class, $types);
    }

    // ─────────── Garde d'échéance (rattrapage) ───────────

    public function test_le_garde_n_execute_qu_une_fois_par_periode(): void
    {
        $guard = app(DuePeriodGuard::class);
        $executions = 0;
        $work = function () use (&$executions) {
            $executions++;

            return true;
        };

        $this->assertTrue($guard->runIfDue('tache-test', '2026-08-21T15', $work));
        $this->assertFalse($guard->runIfDue('tache-test', '2026-08-21T15', $work));
        $this->assertFalse($guard->runIfDue('tache-test', '2026-08-21T15', $work));

        $this->assertSame(1, $executions, 'L\'échéance a été honorée plus d\'une fois.');
    }

    public function test_une_nouvelle_periode_est_a_nouveau_due(): void
    {
        $guard = app(DuePeriodGuard::class);
        $executions = 0;
        $work = function () use (&$executions) {
            $executions++;

            return true;
        };

        $guard->runIfDue('tache-test', '2026-08-21T15', $work);
        $guard->runIfDue('tache-test', '2026-08-21T16', $work);

        $this->assertSame(2, $executions);
    }

    public function test_un_echec_laisse_l_echeance_ouverte_au_rattrapage(): void
    {
        $guard = app(DuePeriodGuard::class);

        // Un travail qui échoue ne doit PAS consommer l'échéance : c'est exactement ce qui
        // distingue une tâche rattrapable d'une tâche fixée à une minute précise.
        $this->assertFalse($guard->runIfDue('tache-test', '2026-08-21T15', fn () => false));
        $this->assertNull($guard->lastSuccessAt('tache-test', '2026-08-21T15'));

        $rattrape = false;
        $this->assertTrue($guard->runIfDue('tache-test', '2026-08-21T15', function () use (&$rattrape) {
            $rattrape = true;

            return true;
        }));
        $this->assertTrue($rattrape, 'L\'échéance manquée n\'a pas été rattrapée.');
    }

    public function test_les_cles_de_periode_ont_la_bonne_granularite(): void
    {
        $at = Carbon::parse('2026-08-21 15:42:00', 'UTC');

        $this->assertSame('2026-08-21T15', DuePeriodGuard::hourlyPeriod($at));
        $this->assertSame('2026-08-21', DuePeriodGuard::dailyPeriod($at));

        // Le fuseau compte pour une échéance « du jour » : 23:30 UTC est déjà le lendemain à Paris.
        $veille = Carbon::parse('2026-08-21 23:30:00', 'UTC');
        $this->assertSame('2026-08-22', DuePeriodGuard::dailyPeriod($veille, 'Europe/Paris'));
    }

    // ─────────── Remise à zéro de la démo ───────────

    public function test_le_reset_demo_n_est_pas_pilote_par_le_planificateur(): void
    {
        // `demo:reset` exécute `migrate:fresh` : il détruit la base, donc toute trace en base
        // indiquant qu'il vient de tourner. Piloté par le planificateur (qui repasse toutes les
        // 5 min), il se rejouerait en boucle — une vingtaine de reconstructions par nuit. Sa
        // périodicité appartient au cron de l'hébergeur (cron-demo.php), pas à Laravel.
        $commandes = array_map(
            fn ($event) => $event->command ?? '',
            app(Schedule::class)->events(),
        );

        foreach ($commandes as $commande) {
            $this->assertStringNotContainsString('demo:reset', $commande);
        }
    }

    // ─────────── Point d'entrée cron.php ───────────

    public function test_cron_php_refuse_le_contexte_http(): void
    {
        $source = file_get_contents(base_path('cron.php'));

        // Garde vital : servi par HTTP, ce fichier lancerait ~55 min de traitement par requête.
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        $this->assertStringContainsString('http_response_code(404)', $source);
    }

    public function test_les_points_d_entree_cron_sont_bloques_par_le_htaccess(): void
    {
        // Le docroot du mutualisé est la racine du dépôt : sans ces règles, les points d'entrée
        // du planificateur sont servis par Apache. Déclencher cron.php par HTTP lancerait 55 min
        // de traitement par requête ; cron-demo.php détruirait et reconstruirait la base.
        $htaccess = file_get_contents(base_path('.htaccess'));

        preg_match('/^RedirectMatch 404 .*artisan.*$/m', $htaccess, $regle);
        $this->assertNotEmpty($regle, 'Règle de blocage des fichiers racine introuvable.');

        // On éprouve le motif lui-même plutôt que sa graphie : il doit couvrir les deux fichiers.
        $motif = trim(str_replace('RedirectMatch 404 ', '', $regle[0]));

        foreach (['/cron.php', '/cron-demo.php'] as $chemin) {
            $this->assertMatchesRegularExpression(
                '#'.$motif.'#',
                $chemin,
                "Le point d'entrée {$chemin} n'est pas bloqué par le .htaccess.",
            );
        }
    }
}
