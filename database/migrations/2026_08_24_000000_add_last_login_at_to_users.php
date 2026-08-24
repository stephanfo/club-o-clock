<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horodatage de la dernière connexion réussie, tous moyens confondus (§4.1.1, §4.1.3).
 *
 * Sans lui, « ce compte est-il activé ? » se déduisait de la présence d'un mot de passe, d'une
 * identité OAuth ou d'une invitation consommée — trois marqueurs qui ratent le parcours le plus
 * courant, le lien magique seul. Un adhérent qui n'entre que par lien restait éternellement
 * « jamais invité » : relance de masse à répétition, bandeau d'alerte faux sur sa fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
