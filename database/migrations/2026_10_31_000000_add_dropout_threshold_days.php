<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Seuil de décrochage du dashboard de pilotage (PRD §4.16.2 bloc 5, §4.17) : un athlète actif sans
// participation training depuis plus de N jours apparaît parmi les « absents ». Défaut 21 jours,
// soit trois semaines de créneaux hebdomadaires manqués.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('dropout_threshold_days')->default(21)->after('season_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            $table->dropColumn('dropout_threshold_days');
        });
    }
};
