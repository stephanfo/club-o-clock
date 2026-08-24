<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage UNIQUE des secrets restés dans le payload d'envois déjà effectués.
 *
 * OutboxDrainer retire le jeton au moment de l'envoi ; restaient les lignes écrites avant, qui
 * gardaient un jeton d'invitation en clair — de quoi entrer dans le compte visé. Ce nettoyage
 * vivait dans la tâche quotidienne `club:prune-tokens`, qui rescannait donc l'intégralité des
 * lignes `sent` chaque jour et pour toujours, pour un travail par nature ponctuel. Il est joué
 * ici une fois, et la tâche redevient à coût constant.
 *
 * Bornée aux lignes `sent` : une ligne `failed` reste rejouable, la vider produirait un lien mort.
 *
 * La liste des clés est FIGÉE ici plutôt que lue sur le modèle : une migration est un fait daté,
 * elle ne doit pas changer de comportement quand la constante évolue.
 */
return new class extends Migration
{
    private const CLES_SENSIBLES = ['token'];

    public function up(): void
    {
        // Réécriture ligne à ligne : le payload est du JSON et il n'existe pas d'UPDATE ensembliste
        // portable entre MySQL et MariaDB pour en retirer une clé. chunkById borne la mémoire.
        DB::table('notification_outbox')
            ->where('status', 'sent')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(200, function ($lignes) {
                foreach ($lignes as $ligne) {
                    $payload = json_decode((string) $ligne->payload, true);
                    if (! is_array($payload)) {
                        continue;
                    }

                    $propre = Arr::except($payload, self::CLES_SENSIBLES);
                    if ($propre === $payload) {
                        continue;
                    }

                    DB::table('notification_outbox')
                        ->where('id', $ligne->id)
                        ->update(['payload' => json_encode($propre)]);
                }
            });
    }

    /**
     * Irréversible par construction : les secrets purgés ne sont pas reconstituables, et on ne
     * voudrait pas les réintroduire.
     */
    public function down(): void {}
};
