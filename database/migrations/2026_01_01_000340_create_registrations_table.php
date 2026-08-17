<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['participating', 'waitlist', 'cancelled']);
            $table->enum('waitlist_reason', ['capacity', 'quota_exceeded'])->nullable();
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->timestamp('registered_at');
            $table->timestamp('promoted_at')->nullable();
            $table->unsignedBigInteger('promoted_by')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->string('override_reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['session_id', 'user_id'], 'registrations_session_id_user_id_unique');
            $table->index(['user_id', 'status'], 'registrations_user_id_status_index');
            $table->index(['session_id', 'status', 'registered_at'], 'registrations_session_id_status_registered_at_index');

            $table->foreign('override_by', 'registrations_override_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('promoted_by', 'registrations_promoted_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('session_id', 'registrations_session_id_foreign')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('user_id', 'registrations_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
