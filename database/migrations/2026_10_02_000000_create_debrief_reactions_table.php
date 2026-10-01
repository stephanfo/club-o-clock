<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Réactions « j'aime » sur les débriefs (#101, PRD §4.12.5). Une par (débrief, membre).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debrief_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('debrief_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['debrief_id', 'user_id'], 'debrief_reactions_debrief_id_user_id_unique');
            $table->index('user_id', 'debrief_reactions_user_id_index');

            $table->foreign('debrief_id', 'debrief_reactions_debrief_id_foreign')->references('id')->on('debriefs')->cascadeOnDelete();
            $table->foreign('user_id', 'debrief_reactions_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debrief_reactions');
    }
};
