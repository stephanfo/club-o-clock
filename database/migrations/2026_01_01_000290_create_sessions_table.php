<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['training', 'competition', 'club_event']);
            $table->string('title');
            $table->unsignedBigInteger('discipline_id')->nullable();
            $table->dateTime('start_at');
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_text')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('visibility')->default('all');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('source_template_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->unsignedBigInteger('quota_tag_id')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->string('content_attachment_path')->nullable();
            $table->unsignedBigInteger('event_type_id')->nullable();
            $table->string('distance')->nullable();
            $table->string('external_url')->nullable();
            $table->string('photos_album_url')->nullable();
            $table->longText('agenda')->nullable();
            $table->string('route_openrunner_embed_url')->nullable();
            $table->string('route_openrunner_public_url')->nullable();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['start_at'], 'sessions_start_at_index');
            $table->index(['kind', 'start_at'], 'sessions_kind_start_at_index');
            $table->index(['quota_tag_id', 'start_at'], 'sessions_quota_tag_id_start_at_index');

            $table->foreign('cancelled_by', 'sessions_cancelled_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', 'sessions_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discipline_id', 'sessions_discipline_id_foreign')->references('id')->on('disciplines')->nullOnDelete();
            $table->foreign('event_type_id', 'sessions_event_type_id_foreign')->references('id')->on('event_types')->nullOnDelete();
            $table->foreign('location_id', 'sessions_location_id_foreign')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('quota_tag_id', 'sessions_quota_tag_id_foreign')->references('id')->on('quota_tags')->nullOnDelete();
            $table->foreign('route_id', 'sessions_route_id_foreign')->references('id')->on('gpx_routes')->nullOnDelete();
            $table->foreign('source_template_id', 'sessions_source_template_id_foreign')->references('id')->on('session_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
