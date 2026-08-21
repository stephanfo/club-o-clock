<?php

use App\Support\DemoMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées — aucune minute d'horloge absolue
|--------------------------------------------------------------------------
|
| Sur hébergement mutualisé, le cron ne se déclenche qu'une fois par heure, à une minute IMPOSÉE
| par l'hébergeur. La boucle `club:cron-boucle` (INSTALL §5.4) appelle `schedule:run` chaque minute
| pendant ~55 min sur 60, mais le trou de couverture restant SE DÉPLACE avec cette minute imposée.
|
| Conséquence vérifiée : aucune minute d'horloge n'est sûre. Une tâche en `hourly()` (`0 * * * *`)
| ou `daily()` (`0 0 * * *`) peut n'être vue JAMAIS — pas « en retard », jamais — selon la minute
| que l'hébergeur a attribuée. Panne totale et silencieuse.
|
| D'où la règle, à ne pas défaire : **toute tâche périodique est planifiée fréquemment et se garde
| elle-même** via DuePeriodGuard (`--if-due`), qui n'honore qu'une exécution par période. La
| sémantique visée est « au moins une fois après l'échéance, une seule fois effectivement », et non
| « seulement si le planificateur passe pile à la bonne minute ».
|
| Bénéfice second : une échéance manquée pour une autre cause (boucle tuée par un quota,
| redémarrage) est rattrapée à la passe suivante, au lieu d'être perdue jusqu'à la période d'après.
|
| Sur VPS, où la crontab appelle `schedule:run` chaque minute, ces réglages restent corrects.
*/

// Drain de l'outbox notifications (§7.13/§7.14) — envoi différé par lots. Seule tâche sans garde
// d'échéance : elle est idempotente par nature (les lignes traitées changent de statut) et son
// travail est de vider une file, pas d'honorer une échéance. `*/5` est vu une dizaine de fois par
// fenêtre quelle que soit la minute imposée.
Schedule::command('notifications:drain')->everyFiveMinutes()->withoutOverlapping(10);

// Pré-calcul météo J-16 (§4.13.5) — échéance horaire, cadence < TTL 3 h.
Schedule::command('weather:refresh --if-due')->everyFiveMinutes()->withoutOverlapping(15);

// Élagage des jetons d'auth expirés/consommés (MagicLinkToken, InvitationToken — Prunable). Borne
// la croissance des tables et purge l'email résiduel des liens consommés (minimisation §4.3).
Schedule::command('club:prune-tokens --if-due')->everyFiveMinutes()->withoutOverlapping(120);

// Remise à zéro nocturne de l'instance de démonstration (plan open source OS7). Trois verrous
// plutôt qu'un : `between()` borne le rattrapage à la nuit (une échéance manquée ne doit jamais
// être reprise en pleine journée, la commande détruit la base), `when()` évite qu'une instance de
// club fasse échouer une tâche planifiée chaque nuit (bruit dans les journaux), et la commande
// refuse elle-même de s'exécuter hors DEMO_MODE — c'est ce dernier verrou qui protège vraiment,
// au plus près de la destruction.
// Fuseau écrit en dur, contrairement au reste de l'app qui lit ClubSettings.timezone : la démo
// est l'instance du projet, pas un club, et routes/console.php ne doit pas taper en base.
Schedule::command('demo:reset --if-due')
    ->everyFiveMinutes()
    ->between('04:00', '06:00')
    ->timezone('Europe/Paris')
    ->when(fn () => DemoMode::enabled())
    ->withoutOverlapping(60);
