<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use Illuminate\Database\Connection;

/**
 * Strategy for database schema introspection in a driver-specific way.
 */
interface SchemaIntrospectionStrategy
{
    /**
     * List all base table names (excluding views) for the connected database.
     *
     * @param Connection $connection The database connection
     * @return array<string> List of table names
     */
    public function listTables(Connection $connection): array;

    /**
     * Get the DDL (CREATE TABLE statement) for a given table.
     *
     * @param Connection $connection The database connection
     * @param string $tableName The table to describe
     * @return string The DDL statement
     */
    public function getCreateTableDdl(Connection $connection, string $tableName): string;

    /**
     * Get table metadata (estimated row count and size in MB).
     *
     * @param Connection $connection The database connection
     * @param string $tableName The table name
     * @return array{row_count: ?int, size_mb: ?float}
     */
    public function getTableMetadata(Connection $connection, string $tableName): array;
}
