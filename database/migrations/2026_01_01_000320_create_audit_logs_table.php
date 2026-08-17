<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['action'], 'audit_logs_action_index');
            $table->index(['target_type', 'target_id'], 'audit_logs_target_type_target_id_index');
            $table->index(['session_id'], 'audit_logs_session_id_index');

            $table->foreign('actor_id', 'audit_logs_actor_id_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('session_id', 'audit_logs_session_id_foreign')->references('id')->on('sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
