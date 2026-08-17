<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_category', function (Blueprint $table) {
            $table->unsignedBigInteger('session_template_id');
            $table->unsignedBigInteger('category_id');
            $table->primary(['session_template_id', 'category_id']);

            $table->foreign('category_id', 'template_category_category_id_foreign')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('session_template_id', 'template_category_session_template_id_foreign')->references('id')->on('session_templates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_category');
    }
};
