<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('http_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->index(['user_id'], 'http_sessions_user_id_index');
            $table->index(['last_activity'], 'http_sessions_last_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('http_sessions');
    }
};
