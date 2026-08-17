<?php

namespace Tests;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le cache par requête du singleton survit au rollback DB de RefreshDatabase (mémoire PHP,
        // pas DB) : on le vide entre tests pour éviter un modèle périmé pointant un id disparu.
        ClubSettings::flushCache();
        // Même profil pour le compteur d'alertes non lues (cache statique par requête) : le vider
        // évite qu'un id d'utilisateur réutilisé lise une valeur mémoïsée d'un test précédent.
        NotificationOutbox::forgetUnreadCount();

        // Horloge figée optionnelle : `CLUB_TEST_NOW="2026-07-12 23:30" php artisan test` fige
        // Carbon::now() (fuseau club) pour toute la suite. Sert à débusquer les tests dépendants
        // de la date réelle (bords de semaine/saison, changement d'heure) sans attendre qu'un
        // dimanche soir les casse. Absente = horloge réelle (comportement normal).
        if (($frozen = env('CLUB_TEST_NOW')) !== null) {
            // Saisie en heure club (Europe/Paris), convertie vers le fuseau applicatif : en prod
            // Carbon::now() vit en app.timezone (UTC) — figer en tz Paris fabriquerait des
            // artefacts (ex. heure locale inexistante au changement d'heure, écritures SQL locales).
            Carbon::setTestNow(Carbon::parse($frozen, 'Europe/Paris')->tz(config('app.timezone')));
        }
    }
}
