<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Allures course (#114, #113) : référentiel par discipline, catalogue des zones (avec alias
// reconnus dans les consignes), table club des allures cibles, valeur de référence (VMA) du
// profil. Les tables sont structurées par référentiel pour accueillir plus tard le vélo (% FTP)
// et la natation (% CSS).
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
            // Autres noms de la zone (« SV2, SubT »), séparés par des virgules.
            $table->string('aliases', 60)->nullable();
            $table->unsignedSmallInteger('pct_min');
            $table->unsignedSmallInteger('pct_max');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['referentiel', 'code'], 'allure_zones_referentiel_code_unique');
        });

        // Table club « % tenable par niveau × distance ». Le modèle de Riegel y est un niveau à
        // part entière (`model` = 'riegel', sans bornes), ordonnable et désactivable ; `active`
        // retire un niveau de ce qui est proposé aux membres sans le supprimer.
        Schema::create('allure_levels', function (Blueprint $table) {
            $table->id();
            $table->string('referentiel', 20);
            $table->string('label', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('model', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->json('targets')->nullable();
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
            'referentiel' => 'course', 'code' => $z[0], 'label' => $z[1], 'aliases' => $z[4],
            'pct_min' => $z[2], 'pct_max' => $z[3], 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['Z1', 'Endurance', 60, 75, 'EF'],
            ['Z2', 'Endurance active', 75, 85, 'EA, SV1'],
            ['Z3', 'Seuil', 85, 90, 'SV2'],
            ['Z4', 'Fractionné long', 90, 95, null],
            ['Z5', 'VMA', 95, 105, null],
        ]));

        // La ligne Riegel existe d'office : une donnée, pas une réinjection par le code.
        DB::table('allure_levels')->insert([
            'referentiel' => 'course', 'label' => 'Modèle de Riegel', 'sort_order' => 0,
            'model' => 'riegel', 'active' => true, 'targets' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
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
