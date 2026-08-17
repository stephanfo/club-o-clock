<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

// Garde-fou contre la divergence entre les migrations et le dump de schéma (revue open source,
// constat n°28).
//
// Le dump database/schema/*.sql construit TOUTES les bases de test (Laravel le charge au début de
// `migrate` pour la connexion du même nom), tandis que la production joue les migrations. Les deux
// représentations n'étaient jamais comparées : `composer check` pouvait rester vert alors que le
// dump n'avait pas été régénéré après une migration, ou qu'une migration était cassée. C'est
// exactement la panne corrigée par a3398c3 — déploiement vert, rupture au runtime en production.
//
// Cette commande reconstruit les deux schémas côte à côte dans des bases jetables et compare leur
// structure. Un écart = le dump est périmé (`php artisan schema:dump`) ou une migration est
// fautive.
class CheckSchemaDriftCommand extends Command
{
    protected $signature = 'schema:check-drift
                            {--connection= : Connexion à contrôler (défaut : celle par défaut)}';

    protected $description = 'Vérifie que le dump de schéma et les migrations produisent le même schéma';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $suffix = 'drift_'.substr(md5((string) microtime(true)), 0, 8);
        $fromDump = "cocdrift_dump_{$suffix}";
        $fromMigrations = "cocdrift_migr_{$suffix}";

        $schemaPath = database_path('schema/'.$connection.'-schema.sql');
        if (! File::exists($schemaPath)) {
            $this->components->warn("Aucun dump pour la connexion « {$connection} » — rien à comparer.");

            return self::SUCCESS;
        }

        try {
            // Base A : le DUMP SEUL, sans jouer les migrations restantes par-dessus. C'est bien le
            // dump qu'on met à l'épreuve : le laisser compléter par les migrations en attente
            // masquerait précisément ce qu'on cherche (un dump périmé), les deux bases finissant
            // identiques quoi qu'il arrive.
            // Base B : les migrations seules, dump ignoré (= ce que joue la production).
            $this->loadDumpOnly($connection, $fromDump, $schemaPath);
            $this->buildDatabase($connection, $fromMigrations, ignoreSchemaDump: true);

            $a = $this->describe($connection, $fromDump);
            $b = $this->describe($connection, $fromMigrations);
        } catch (Throwable $e) {
            $this->components->error('Contrôle impossible : '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->dropDatabase($connection, $fromDump);
            $this->dropDatabase($connection, $fromMigrations);
        }

        if ($a === $b) {
            $this->components->info('Schéma cohérent : le dump et les migrations produisent la même structure.');

            return self::SUCCESS;
        }

        $this->components->error('Le dump et les migrations divergent.');
        $this->line('');
        foreach ($this->diff($a, $b) as $line) {
            $this->line('  '.$line);
        }
        $this->line('');
        $this->components->warn('Régénère le dump après toute migration : php artisan schema:dump');

        return self::FAILURE;
    }

    /**
     * Crée une base jetable et y charge le dump, SANS jouer la moindre migration ensuite.
     *
     * C'est l'état exact que le dump prétend représenter : s'il a été régénéré après la dernière
     * migration, il doit à lui seul reproduire le schéma complet.
     */
    private function loadDumpOnly(string $connection, string $database, string $schemaPath): void
    {
        DB::connection($connection)->statement("CREATE DATABASE `{$database}`");

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        // getSchemaState() n'est pas porté par la classe de base Connection : seuls les pilotes qui
        // savent charger un dump l'exposent (MySQL/MariaDB, PostgreSQL, SQLite). Sur un pilote qui
        // ne le sait pas, il n'y a de toute façon pas de dump à confronter.
        $db = DB::connection($connection);

        if (! $db instanceof MySqlConnection && ! $db instanceof PostgresConnection && ! $db instanceof SQLiteConnection) {
            throw new RuntimeException("La connexion « {$connection} » ne sait pas charger un dump de schéma.");
        }

        $db->getSchemaState()->load($schemaPath);

        DB::purge($connection);
    }

    /**
     * Crée une base jetable et y joue les migrations.
     *
     * @param  bool  $ignoreSchemaDump  true = force la relecture COMPLÈTE des migrations (ce que
     *                                  joue la production) ; false = laisse Laravel charger le dump
     *                                  s'il existe (ce que voient les tests).
     */
    private function buildDatabase(string $connection, string $database, bool $ignoreSchemaDump): void
    {
        DB::connection($connection)->statement("CREATE DATABASE `{$database}`");

        // La connexion est reconfigurée à la volée pour viser la base jetable, sans toucher au .env.
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        // Pour ignorer le dump, on déplace la racine `database/` vers un dossier temporaire qui ne
        // contient QUE les migrations : MigrateCommand y cherche database/schema/<conn>-schema.sql,
        // ne le trouve pas, et joue donc toutes les migrations. --schema-path='' ne marcherait pas
        // (l'option est testée en truthy, une chaîne vide est ignorée → dump chargé quand même) et
        // un fichier vide non plus (le dépôt de migrations est supprimé sans être recréé).
        $originalPath = $this->laravel->databasePath();
        $sandbox = null;

        if ($ignoreSchemaDump) {
            $sandbox = sys_get_temp_dir().'/coc-drift-'.substr(md5($database), 0, 10);
            File::ensureDirectoryExists($sandbox);
            File::copyDirectory($originalPath.'/migrations', $sandbox.'/migrations');
            $this->laravel->useDatabasePath($sandbox);
        }

        try {
            $this->callSilently('migrate', [
                '--database' => $connection,
                '--force' => true,
                '--no-interaction' => true,
            ]);
        } finally {
            if ($sandbox !== null) {
                $this->laravel->useDatabasePath($originalPath);
                File::deleteDirectory($sandbox);
            }
        }

        DB::purge($connection);
    }

    /**
     * Empreinte structurelle : tables, colonnes (type, nullabilité, défaut), index et clés
     * étrangères. La table `migrations` est exclue — son contenu diffère légitimement (le dump
     * porte des lignes déjà jouées), c'est la STRUCTURE qui doit correspondre.
     *
     * @return array<int, string>
     */
    private function describe(string $connection, string $database): array
    {
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);
        $db = DB::connection($connection);

        $columns = $db->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, COLUMN_NAME',
            [$database],
        );

        $indexes = $db->select(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$database],
        );

        $foreignKeys = $db->select(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    r.DELETE_RULE, r.UPDATE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
             WHERE k.TABLE_SCHEMA = ? ORDER BY k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME',
            [$database],
        );

        $lines = [];

        foreach ($columns as $c) {
            if ($c->TABLE_NAME === 'migrations') {
                continue;
            }
            $lines[] = sprintf(
                'colonne %s.%s %s %s défaut=%s %s',
                $c->TABLE_NAME, $c->COLUMN_NAME, $c->COLUMN_TYPE,
                $c->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL',
                $c->COLUMN_DEFAULT ?? '∅', $c->EXTRA,
            );
        }

        foreach ($indexes as $i) {
            if ($i->TABLE_NAME === 'migrations') {
                continue;
            }
            $lines[] = sprintf(
                'index %s.%s [%d] %s %s',
                $i->TABLE_NAME, $i->INDEX_NAME, $i->SEQ_IN_INDEX,
                $i->COLUMN_NAME, $i->NON_UNIQUE ? '' : 'UNIQUE',
            );
        }

        foreach ($foreignKeys as $f) {
            if ($f->REFERENCED_TABLE_NAME === null) {
                continue;
            }
            $lines[] = sprintf(
                'fk %s.%s → %s.%s ON DELETE %s ON UPDATE %s',
                $f->TABLE_NAME, $f->COLUMN_NAME, $f->REFERENCED_TABLE_NAME,
                $f->REFERENCED_COLUMN_NAME, $f->DELETE_RULE, $f->UPDATE_RULE,
            );
        }

        sort($lines);

        return $lines;
    }

    /**
     * @param  array<int, string>  $fromDump
     * @param  array<int, string>  $fromMigrations
     * @return array<int, string>
     */
    private function diff(array $fromDump, array $fromMigrations): array
    {
        $out = [];

        foreach (array_diff($fromMigrations, $fromDump) as $line) {
            $out[] = "+ (migrations seules, absent du dump) {$line}";
        }

        foreach (array_diff($fromDump, $fromMigrations) as $line) {
            $out[] = "- (dump seul, absent des migrations) {$line}";
        }

        return array_slice($out, 0, 40);
    }

    private function dropDatabase(string $connection, string $database): void
    {
        try {
            // On repasse par une base sûre : impossible de supprimer celle sur laquelle on est.
            config(["database.connections.{$connection}.database" => 'information_schema']);
            DB::purge($connection);
            DB::connection($connection)->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::purge($connection);
        } catch (Throwable) {
            // Nettoyage best-effort : une base jetable oubliée ne doit pas faire échouer le contrôle.
        }
    }
}
