<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ouverture des inscriptions officielles d'une compétition (#105, PRD §4.7 et §4.15.2).
 *
 * Sur les courses prisées, l'inscription chez l'organisateur ouvre des mois avant et se remplit en
 * quelques jours : la date de la course ne suffit pas, il faut celle de l'ouverture, et être prévenu.
 *
 * - `registration_opens_at` : instant UTC. L'heure est souvent inconnue à la saisie ; sans heure,
 *   on stocke minuit heure club, et `registration_opens_has_time` dit que l'heure n'est pas connue
 *   (une ouverture à minuit pile existe, d'où un drapeau plutôt qu'une heure « magique »).
 * - `registration_opening_notified_at` : la notification d'ouverture est partie, ou n'a plus lieu
 *   de partir (date déjà passée à la saisie). Remis à null quand la date change vers le futur :
 *   c'est ainsi que l'envoi se replanifie, sans file d'attente à corriger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dateTime('registration_opens_at')->nullable()->after('photos_album_url');
            $table->boolean('registration_opens_has_time')->default(false)->after('registration_opens_at');
            $table->timestamp('registration_opening_notified_at')->nullable()->after('registration_opens_has_time');
            $table->index('registration_opens_at');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropIndex(['registration_opens_at']);
            $table->dropColumn(['registration_opens_at', 'registration_opens_has_time', 'registration_opening_notified_at']);
        });
    }
};
