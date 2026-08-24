<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Code à usage unique joint au lien magique (PRD §4.1.1).
//
// Deux colonnes sur la table du lien, et non une table à part : le code et le lien sont les deux
// faces d'une MÊME autorisation — même destinataire, même TTL, même consommation. Une table séparée
// dupliquerait ce cycle de vie et rendrait possible un état incohérent (lien consommé, code encore
// vivant).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magic_link_tokens', function (Blueprint $table) {
            // Nullable : les jetons émis sans code (harnais E2E) et ceux déjà en vol à la migration
            // n'en ont pas. Pas d'index unique — deux adhérents peuvent légitimement tirer le même
            // code à 6 chiffres, seule la paire (email, code) est discriminante.
            $table->string('code_hash', 64)->nullable()->after('token_hash');

            // Verrou anti-force brute : 6 chiffres ≈ 20 bits, le TTL seul ne suffit pas.
            $table->unsignedTinyInteger('code_attempts')->default(0)->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('magic_link_tokens', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'code_attempts']);
        });
    }
};
