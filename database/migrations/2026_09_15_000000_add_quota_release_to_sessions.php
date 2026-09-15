<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Déblocage du quota persistant jusqu'à la séance (#66, PRD §4.10.4).
 *
 * Le mécanisme C était un geste ponctuel : il promouvait la file `quota_exceeded` à l'instant du
 * clic et ne laissait aucune trace sur la séance. Une inscription hors quota arrivée après, un
 * désistement ou une hausse de capacité retombaient dans la règle normale, et la place restait
 * libre alors que des athlètes attendaient.
 *
 * Le déblocage devient donc un ÉTAT de la séance. Un horodatage plutôt qu'un booléen : il porte
 * l'information (débloqué ou non) et le moment, sans colonne à maintenir synchrone. L'auteur est
 * gardé pour l'affichage coach ; le motif, lui, vit dans l'AuditLog `quota_release`, comme pour
 * les autres gestes tracés. `nullOnDelete` : la suppression d'un compte ne doit pas refermer le
 * quota des séances qu'il a débloquées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->timestamp('quota_released_at')->nullable()->after('quota_tag_id');
            $table->foreignId('quota_released_by')->nullable()->after('quota_released_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quota_released_by');
            $table->dropColumn('quota_released_at');
        });
    }
};
