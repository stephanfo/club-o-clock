<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_qualification', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('qualification_id');
            $table->date('expires_at')->nullable();
            $table->timestamp('attributed_at');
            $table->unsignedBigInteger('attributed_by')->nullable();
            $table->primary(['user_id', 'qualification_id']);

            $table->foreign('attributed_by', 'user_qualification_attributed_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('qualification_id', 'user_qualification_qualification_id_foreign')->references('id')->on('qualifications')->cascadeOnDelete();
            $table->foreign('user_id', 'user_qualification_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_qualification');
    }
};
