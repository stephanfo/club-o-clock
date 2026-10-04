<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Allures course (#114) : référentiel par discipline, catalogue des zones, table club des
// allures cibles, valeur de référence (VMA) du profil. Les tables sont structurées par
// référentiel pour accueillir plus tard le vélo (% FTP) et la natation (% CSS).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disciplines', function (Blueprint $table) {
            $table->string('referentiel', 20)->nullable()->after('label');
        });

        Schema::create('allure_zones', function (Blueprint $table) {
            $table->id();
            $table->string('referentiel', 20);
            $table->string('code', 12);
            $table->string('label', 120);
            $table->unsignedSmallInteger('pct_min');
            $table->unsignedSmallInteger('pct_max');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['referentiel', 'code'], 'allure_zones_referentiel_code_unique');
        });

        // Table club « % tenable par niveau × distance ». Vide = modèle de Riegel par défaut.
        Schema::create('allure_levels', function (Blueprint $table) {
            $table->id();
            $table->string('referentiel', 20);
            $table->string('label', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('targets');
            $table->timestamps();
        });

        // Une valeur courante par (membre, référentiel), sans historique (§3.2). Jamais de chrono.
        Schema::create('reference_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('referentiel', 20);
            $table->decimal('value', 5, 1);
            $table->string('source', 20);
            $table->string('source_distance', 20)->nullable();
            $table->date('measured_on');
            $table->timestamps();
            $table->unique(['user_id', 'referentiel'], 'reference_values_user_id_referentiel_unique');
            $table->foreign('user_id', 'reference_values_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });

        // Instance déjà déployée : la discipline « Course à pied » du seed reçoit le référentiel,
        // et la grille générique est posée si le catalogue est vide. Le coach la remplace ensuite
        // par la sienne dans l'admin — ses coefficients ne vont jamais dans le dépôt.
        DB::table('disciplines')->where('label', 'Course à pied')->update(['referentiel' => 'course']);

        $now = Carbon::now();
        DB::table('allure_zones')->insert(array_map(fn ($z) => [
            'referentiel' => 'course', 'code' => $z[0], 'label' => $z[1], 'pct_min' => $z[2], 'pct_max' => $z[3],
            'created_at' => $now, 'updated_at' => $now,
        ], [
            ['Z1', 'Endurance', 60, 75],
            ['Z2', 'Endurance active', 75, 85],
            ['Z3', 'Seuil', 85, 90],
            ['Z4', 'Fractionné long', 90, 95],
            ['Z5', 'VMA', 95, 105],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_values');
        Schema::dropIfExists('allure_levels');
        Schema::dropIfExists('allure_zones');
        Schema::table('disciplines', function (Blueprint $table) {
            $table->dropColumn('referentiel');
        });
    }
};
