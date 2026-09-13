<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adresse ponctuelle géocodée sur une séance (#37, PRD §4.13.4).
 *
 * Une compétition ou un événement club se tient dans un endroit qui ne revient pas : créer un
 * `Location` de catalogue pour chacun polluerait la bibliothèque de lieux favoris d'entrées à
 * usage unique. Ces trois colonnes portent donc l'adresse *sur la séance*, en miroir exact de
 * `locations` (mêmes types) — l'alternative au lieu favori, d'où le préfixe volontairement
 * verbeux `ad_hoc_`, qu'un `address` nu ne dirait pas.
 *
 * `location_id` et `ad_hoc_address` sont mutuellement exclusifs (gardé côté serveur par
 * SessionForm). `location_text` est INCHANGÉ : il redevient la *précision* (« RDV parking nord »)
 * qui s'ajoute au lieu au lieu de le remplacer — aucune migration de données, aucun texte
 * existant perdu ni réinterprété (cf. Session::placeLabel()).
 *
 * Rien sur `session_templates` : un lieu récurrent mérite d'entrer au catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('ad_hoc_address')->nullable()->after('location_text');
            $table->decimal('ad_hoc_latitude', 10, 7)->nullable()->after('ad_hoc_address');
            $table->decimal('ad_hoc_longitude', 10, 7)->nullable()->after('ad_hoc_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn(['ad_hoc_address', 'ad_hoc_latitude', 'ad_hoc_longitude']);
        });
    }
};
