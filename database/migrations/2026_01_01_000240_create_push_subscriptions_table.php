<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('p256dh');
            $table->string('auth');
            $table->string('content_encoding')->default('aesgcm');
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['endpoint_hash'], 'push_subscriptions_endpoint_hash_unique');
            $table->index(['user_id'], 'push_subscriptions_user_id_index');

            $table->foreign('user_id', 'push_subscriptions_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
