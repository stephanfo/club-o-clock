<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_coach', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['session_id', 'user_id']);

            $table->foreign('session_id', 'session_coach_session_id_foreign')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('user_id', 'session_coach_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_coach');
    }
};
