<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debriefs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->longText('content_markdown');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['session_id', 'author_id'], 'debriefs_session_id_author_id_unique');

            $table->foreign('archived_by', 'debriefs_archived_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('author_id', 'debriefs_author_id_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('session_id', 'debriefs_session_id_foreign')->references('id')->on('sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debriefs');
    }
};
