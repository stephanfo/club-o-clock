<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->longText('content_markdown')->nullable();
            $table->string('visibility', 10)->default('all');
            $table->boolean('pinned')->default(0);
            $table->integer('position')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['visibility'], 'information_pages_visibility_index');
            $table->index(['pinned'], 'information_pages_pinned_index');
            $table->index(['position'], 'information_pages_position_index');

            $table->foreign('archived_by', 'information_pages_archived_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', 'information_pages_created_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('information_pages');
    }
};
