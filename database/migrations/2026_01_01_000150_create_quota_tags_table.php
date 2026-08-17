<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_tags', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('label');
            $table->unsignedSmallInteger('max_per_week')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'quota_tags_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_tags');
    }
};
