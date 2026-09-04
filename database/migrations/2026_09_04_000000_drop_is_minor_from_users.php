<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression de `users.is_minor` : la minorité se déduit de la date de naissance.
 *
 * La colonne n'était écrite qu'à trois moments — création, édition de la date de naissance,
 * import — et jamais recalculée ensuite : ni la bascule de saison, ni aucune tâche planifiée ne la
 * rafraîchissaient. Elle vieillissait donc à contretemps de la personne qu'elle décrit. Un enfant
 * inscrit à dix ans restait mineur en base longtemps après ses dix-huit ans ; à l'inverse, un
 * adulte créé alors qu'il était mineur restait marqué mineur et se voyait refuser le rôle de
 * garant. Une donnée dérivable, stockée sans être maintenue, ne peut que dériver.
 *
 * La condition SQL équivalente est une comparaison de date, indexable au même titre (cf. les scopes
 * mineur()/majeur() du modèle User) : rien n'est perdu en performance, et plus rien ne peut mentir.
 *
 * Aucune reprise de données n'est nécessaire — c'est tout l'intérêt. Le down() reconstruit la
 * colonne et la remplit depuis `dob`, ce qui la rend d'emblée plus juste qu'elle ne l'était.
 *
 * Constats : doc/RETOURS_TERRAIN.md, entrée du 2026-09-04, points 2 et 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_minor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_minor')->default(0)->after('athlete_access_suspended');
        });

        // Minorité légale au jour de la restauration : né après cette date, moins de 18 ans révolus.
        DB::table('users')
            ->whereNotNull('dob')
            ->whereDate('dob', '>', Carbon::now()->startOfDay()->subYears(18))
            ->update(['is_minor' => true]);
    }
};
