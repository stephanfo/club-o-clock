<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_templates', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->enum('kind', ['training', 'competition', 'club_event']);
            $table->unsignedBigInteger('discipline_id')->nullable();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time_of_day');
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_text')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedBigInteger('quota_tag_id')->nullable();
            $table->date('generation_start_date');
            $table->date('generation_end_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('created_by', 'session_templates_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discipline_id', 'session_templates_discipline_id_foreign')->references('id')->on('disciplines')->nullOnDelete();
            $table->foreign('location_id', 'session_templates_location_id_foreign')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('quota_tag_id', 'session_templates_quota_tag_id_foreign')->references('id')->on('quota_tags')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_templates');
    }
};
