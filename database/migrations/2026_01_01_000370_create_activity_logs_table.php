<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->boolean('actor_is_system')->default(0);
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->string('resulting_status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['action'], 'activity_logs_action_index');
            $table->index(['user_id'], 'activity_logs_user_id_index');
            $table->index(['session_id'], 'activity_logs_session_id_index');

            $table->foreign('actor_id', 'activity_logs_actor_id_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('registration_id', 'activity_logs_registration_id_foreign')->references('id')->on('registrations')->nullOnDelete();
            $table->foreign('session_id', 'activity_logs_session_id_foreign')->references('id')->on('sessions')->nullOnDelete();
            $table->foreign('user_id', 'activity_logs_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
