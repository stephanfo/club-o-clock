<?php

use App\Support\DemoMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pré-calcul météo J-16 (§4.13.5) — cadence < TTL 3 h. Lancé par le cron unique (schedule:run).
Schedule::command('weather:refresh')->hourly()->withoutOverlapping();

// Drain de l'outbox notifications (§7.13/§7.14) — envoi différé par lots, latence bornée par la
// cadence du cron unique (~5 min). Même chemin que le drain à la demande (envoi prioritaire).
Schedule::command('notifications:drain')->everyFiveMinutes()->withoutOverlapping();

// Élagage des jetons d'auth expirés/consommés (MagicLinkToken, InvitationToken — Prunable). Borne
// la croissance des tables et purge l'email résiduel des liens consommés (minimisation §4.3).
Schedule::command('model:prune')->daily();

// Remise à zéro nocturne de l'instance de démonstration (plan open source OS7). Deux verrous
// plutôt qu'un : `when()` évite qu'une instance de club fasse échouer une tâche planifiée chaque
// nuit (bruit dans les journaux), et la commande refuse elle-même de s'exécuter hors DEMO_MODE —
// c'est ce second verrou qui protège vraiment, au plus près de la destruction.
// Fuseau écrit en dur, contrairement au reste de l'app qui lit ClubSettings.timezone : la démo
// est l'instance du projet, pas un club, et routes/console.php ne doit pas taper en base.
Schedule::command('demo:reset')
    ->dailyAt('04:00')
    ->timezone('Europe/Paris')
    ->when(fn () => DemoMode::enabled())
    ->withoutOverlapping();
