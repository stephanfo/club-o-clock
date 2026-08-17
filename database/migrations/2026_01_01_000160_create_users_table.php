<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->date('dob')->nullable();
            $table->json('roles')->nullable();
            $table->boolean('is_active')->default(1);
            $table->boolean('athlete_access_suspended')->default(0);
            $table->boolean('is_minor')->default(0);
            $table->unsignedBigInteger('guardian_id')->nullable();
            $table->timestamp('guardianship_linked_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['email'], 'users_email_unique');
            $table->index(['guardian_id'], 'users_guardian_id_index');
        });

        // Auto-reference : posée après coup, la table n'existe pas encore
        // au moment du create().
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('guardian_id', 'users_guardian_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
