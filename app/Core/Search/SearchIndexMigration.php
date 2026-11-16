<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers for reversible, SQLite-safe search index migrations (#531).
 */
final class SearchIndexMigration
{
    public static function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $database = Schema::getConnection()->getDatabaseName();
            $count = DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->count();

            return $count > 0;
        }

        return false;
    }

    public static function addIndex(string $table, string $index, callable $callback): void
    {
        if (self::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, $callback);
    }

    public static function dropIndex(string $table, string $index, callable $callback): void
    {
        if (! self::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, $callback);
    }

    public static function addFullText(string $table, string $index, string $columnsSql): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || self::hasIndex($table, $index)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD FULLTEXT {$index} ({$columnsSql})");
    }

    public static function dropFullText(string $table, string $index): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || ! self::hasIndex($table, $index)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
    }
}
