<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpx_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('discipline_id')->nullable();
            $table->string('gpx_path');
            $table->char('gpx_hash', 64)->nullable();
            $table->string('gpx_original_name')->nullable();
            $table->unsignedInteger('gpx_size_ko')->nullable();
            $table->decimal('distance_km', 6, 1)->nullable();
            $table->unsignedMediumInteger('dplus_m')->nullable();
            $table->unsignedMediumInteger('dmoins_m')->nullable();
            $table->smallInteger('alt_min_m')->nullable();
            $table->smallInteger('alt_max_m')->nullable();
            $table->unsignedInteger('point_count')->nullable();
            $table->unsignedInteger('duration_min')->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->boolean('is_loop')->default(0);
            $table->decimal('elongation', 4, 2)->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('bearing_deg')->nullable();
            $table->char('sector', 2)->nullable();
            $table->json('polyline')->nullable();
            $table->json('elevation_profile')->nullable();
            $table->string('openrunner_embed_url', 500)->nullable();
            $table->string('openrunner_public_url', 500)->nullable();
            $table->unsignedBigInteger('start_location_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['archived_at', 'sector'], 'gpx_routes_archived_at_sector_index');
            $table->index(['archived_at', 'distance_km'], 'gpx_routes_archived_at_distance_km_index');
            $table->index(['archived_at', 'elongation'], 'gpx_routes_archived_at_elongation_index');
            $table->index(['bbox_min_lat', 'bbox_max_lat'], 'gpx_routes_bbox_min_lat_bbox_max_lat_index');
            $table->index(['gpx_hash'], 'gpx_routes_gpx_hash_index');

            $table->foreign('archived_by', 'gpx_routes_archived_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', 'gpx_routes_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discipline_id', 'gpx_routes_discipline_id_foreign')->references('id')->on('disciplines')->nullOnDelete();
            $table->foreign('start_location_id', 'gpx_routes_start_location_id_foreign')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpx_routes');
    }
};
