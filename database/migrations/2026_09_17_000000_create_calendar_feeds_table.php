<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnement personnel à l'agenda (#39, PRD §4.21.2).
 *
 * Le jeton est stocké en clair : c'est un secret d'URL, pas un mot de passe, et il doit rester
 * ré-affichable pour être ajouté sur un second appareil. La garde est la révocation : une ligne
 * révoquée est conservée pour répondre « disparu » (410) plutôt qu'« introuvable » (404).
 * Les réglages de contenu vivent sur la ligne et sont recopiés à la régénération.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token', 64)->unique();
            $table->boolean('include_waitlist')->default(true);
            $table->boolean('include_coaching')->default(true);
            $table->boolean('include_wards')->default(true);
            // null = aucun rappel ; « 60 », « 120 » (minutes) ou « veille ».
            $table->string('reminder', 10)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
