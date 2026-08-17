<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('channel');
            $table->json('payload');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'cancelled'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['status', 'available_at'], 'notification_outbox_status_available_at_index');

            $table->foreign('user_id', 'notification_outbox_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
