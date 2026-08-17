<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_cache_entries', function (Blueprint $table) {
            $table->id();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->dateTime('slot');
            $table->json('forecast');
            $table->timestamp('fetched_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['latitude', 'longitude', 'slot'], 'weather_cache_entries_latitude_longitude_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_cache_entries');
    }
};
