<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_category', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('category_id');
            $table->primary(['session_id', 'category_id']);

            $table->foreign('category_id', 'session_category_category_id_foreign')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('session_id', 'session_category_session_id_foreign')->references('id')->on('sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_category');
    }
};
