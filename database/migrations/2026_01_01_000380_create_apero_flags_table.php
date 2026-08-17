<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apero_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('registration_id');
            $table->string('motif', 140)->nullable();
            $table->timestamp('flagged_at');
            $table->unsignedBigInteger('flagged_by')->nullable();
            $table->timestamp('parked_at')->nullable();
            $table->unique(['session_id', 'user_id'], 'apero_flags_session_id_user_id_unique');

            $table->foreign('flagged_by', 'apero_flags_flagged_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('registration_id', 'apero_flags_registration_id_foreign')->references('id')->on('registrations')->cascadeOnDelete();
            $table->foreign('session_id', 'apero_flags_session_id_foreign')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('user_id', 'apero_flags_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apero_flags');
    }
};
