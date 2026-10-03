<?php

class TrackerSchema
{
    /**
     * Load schema configuration.
     */
    private static function getConfig(): array
    {
        return include __DIR__ . '/schema-config.php';
    }

    /**
     * Return pure column definitions for all tables.
     *
     * @return array<string, array<string, string>>
     */
    public static function getTables(): array
    {
        return self::getConfig();
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
