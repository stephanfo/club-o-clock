<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->string('provider_uid');
            $table->string('email_at_link')->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['provider', 'provider_uid'], 'auth_identities_provider_provider_uid_unique');

            $table->foreign('user_id', 'auth_identities_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_identities');
    }
};
