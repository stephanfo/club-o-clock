<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['token_hash'], 'magic_link_tokens_token_hash_unique');
            $table->index(['email'], 'magic_link_tokens_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
    }
};
