<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_coach', function (Blueprint $table) {
            $table->unsignedBigInteger('session_template_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['session_template_id', 'user_id']);

            $table->foreign('session_template_id', 'template_coach_session_template_id_foreign')->references('id')->on('session_templates')->cascadeOnDelete();
            $table->foreign('user_id', 'template_coach_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_coach');
    }
};
