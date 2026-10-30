<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Trombinoscope et annuaire consentis (#72, PRD §4.23, cadrage §7.18). Registre `consents` en
// ajout seul (preuve RGPD art. 7.1) et trois colonnes `users` écrites par l'intéressé seul : la
// fonction au bureau (par l'admin), le téléphone et la photo (jamais par l'admin).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('board_role', 30)->nullable()->after('roles');
            $table->string('phone', 20)->nullable()->after('board_role');
            $table->string('photo_path')->nullable()->after('phone');
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 40);
            $table->unsignedSmallInteger('version');
            $table->timestamp('granted_at');
            $table->unsignedBigInteger('granted_by')->nullable();
            // Origine d'un accord recueilli hors de l'app (« bulletin d'adhésion 2027 »).
            $table->string('source', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->index(['user_id', 'type', 'revoked_at'], 'consents_user_id_type_revoked_at_index');

            $table->foreign('user_id', 'consents_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by', 'consents_granted_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by', 'consents_revoked_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['board_role', 'phone', 'photo_path']);
        });
    }
};
