<?php

class TrackerSchema
{
    /**
     * Tables living in data/tracker-raw.sqlite3 instead of the main
     * tracker.sqlite3 - see the "Two database files" note in
     * global/functions.php.
     */
    public const RAW_TABLES = ['raw_hits'];

    /**
     * Load schema configuration.
     */
    private static function getConfig(): array
    {
        return include __DIR__ . '/schema-config.php';
    }

    /**
     * Column definitions for the tables in the main tracker.sqlite3.
     *
     * @return array<string, array<string, string>>
     */
    public static function getTables(): array
    {
        return array_diff_key(self::getConfig(), array_flip(self::RAW_TABLES));
    }

    /**
     * Column definitions for the tables in tracker-raw.sqlite3.
     *
     * @return array<string, array<string, string>>
     */
    public static function getRawTables(): array
    {
        return array_intersect_key(self::getConfig(), array_flip(self::RAW_TABLES));
    }

    /**
     * Get columns for specific table (for migrations).
     */
    public static function getTableColumns(string $tableName): array
    {
        $config = self::getConfig();
        return $config[$tableName] ?? [];
    }
}
