<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Allures cibles (#114) : le modèle de Riegel devient un niveau de la table à part entière
// (`model` = 'riegel', sans bornes), et tout niveau peut être retiré de ce qui est proposé aux
// membres sans être supprimé (`active`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allure_levels', function (Blueprint $table) {
            $table->string('model', 20)->nullable()->after('sort_order');
            $table->boolean('active')->default(true)->after('model');
            $table->json('targets')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('allure_levels', function (Blueprint $table) {
            $table->dropColumn(['model', 'active']);
        });
    }
};
