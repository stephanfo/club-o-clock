<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait manuel d'une alerte (#79, PRD §4.15).
 *
 * La page Alertes lit la file d'envoi. Masquer une alerte ne doit pas effacer la ligne : elle reste
 * l'historique de l'écran des envois (§4.15.6). Une ligne n'a qu'un destinataire, donc un
 * horodatage sur la ligne suffit à masquer « pour ce destinataire seulement ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
