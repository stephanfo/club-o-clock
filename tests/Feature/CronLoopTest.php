<?php

namespace Tests\Feature;

use App\Services\DuePeriodGuard;
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

    // ─────────── Point d'entrée cron.php ───────────

    public function test_cron_php_refuse_le_contexte_http(): void
    {
        $source = file_get_contents(base_path('cron.php'));

        // Garde vital : servi par HTTP, ce fichier lancerait ~55 min de traitement par requête.
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        $this->assertStringContainsString('http_response_code(404)', $source);
    }

    public function test_cron_php_est_bloque_par_le_htaccess(): void
    {
        // Le docroot du mutualisé est la racine du dépôt : sans cette règle, cron.php est servi.
        $this->assertMatchesRegularExpression(
            '/RedirectMatch 404 .*cron\\\\\.php/',
            file_get_contents(base_path('.htaccess')),
        );
    }
}
