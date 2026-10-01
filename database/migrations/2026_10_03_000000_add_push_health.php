<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Santé du push (#97) : issue `no_target` sur l'outbox (personne à qui envoyer, distinct de
// `sent`) et suivi des succès / échecs par appareil, support de la purge des abonnements morts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'failed', 'cancelled', 'no_target'])->default('pending')->change();
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->timestamp('last_success_at')->nullable()->after('user_agent');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
            $table->unsignedSmallInteger('failure_count')->default(0)->after('last_failure_at');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['last_success_at', 'last_failure_at', 'failure_count']);
        });

        // Sans destinataire était compté `sent` avant cette migration.
        DB::table('notification_outbox')->where('status', 'no_target')->update(['status' => 'sent']);
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'failed', 'cancelled'])->default('pending')->change();
        });
    }
};
