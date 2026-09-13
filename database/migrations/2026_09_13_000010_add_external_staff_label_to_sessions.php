<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intervenant extérieur sur une séance d'entraînement (#38, PRD §4.11.4).
 *
 * La piscine du dimanche est encadrée par un surveillant de baignade prestataire, qui n'est ni
 * adhérent ni titulaire d'un compte : la séance passait pour non encadrée (bandeau d'alerte
 * permanent, compteur admin « séances futures sans coach » pollué).
 *
 * Un `User` fictif a été écarté — il ferait pousser des notifications vers une adresse morte à
 * chaque geste sur la séance, entrerait dans le picker coach et les stats, et stocker le vrai nom
 * du prestataire créerait une fiche sur une personne non-adhérente sans base légale.
 * L'information « un intervenant extérieur assure cette séance » est une propriété de la SÉANCE,
 * pas une personne : elle n'est donc pas nominative et vit ici.
 *
 * Une seule colonne, pas de booléen séparé à maintenir synchrone : `null` = pas d'intervenant.
 * Sur `session_templates` aussi, parce que c'est le cœur du cas d'usage — le libellé se règle une
 * fois pour la saison au lieu d'être ressaisi sur chaque occurrence générée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('external_staff_label', 120)->nullable()->after('location_text');
        });

        Schema::table('session_templates', function (Blueprint $table) {
            $table->string('external_staff_label', 120)->nullable()->after('location_text');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('external_staff_label');
        });

        Schema::table('session_templates', function (Blueprint $table) {
            $table->dropColumn('external_staff_label');
        });
    }
};
