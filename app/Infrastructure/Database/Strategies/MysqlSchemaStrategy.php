<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use App\Exceptions\TableNotFoundException;
use Illuminate\Database\Connection;

/**
 * MySQL-specific schema introspection using INFORMATION_SCHEMA and SHOW CREATE TABLE.
 */
class MysqlSchemaStrategy implements SchemaIntrospectionStrategy
{
    /**
     * List base tables using INFORMATION_SCHEMA.
     *
     * @return array<string>
     */
    public function listTables(Connection $connection): array
    {
        $databaseName = $connection->getDatabaseName();

        $tables = $connection->select("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ?
            AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ", [$databaseName]);

        return array_column($tables, 'TABLE_NAME');
    }

    /**
     * Get DDL using MySQL's SHOW CREATE TABLE.
     */
    public function getCreateTableDdl(Connection $connection, string $tableName): string
    {
        $quotedTable = $connection->getQueryGrammar()
            ->wrapTable($tableName);

        $result = $connection->select("SHOW CREATE TABLE {$quotedTable}");

        if (empty($result)) {
            throw new TableNotFoundException("Table '{$tableName}' not found or not accessible");
        }

        $firstResult = $result[0];

        if (is_object($firstResult)) {
            return $firstResult->{'Create Table'} ?? $firstResult->{'create table'} ?? '';
        }

        return $firstResult['Create Table'] ?? $firstResult['create table'] ?? '';
    }

    /**
     * Get table metadata from INFORMATION_SCHEMA.
     *
     * @return array{row_count: ?int, size_mb: ?float}
     */
    public function getTableMetadata(Connection $connection, string $tableName): array
    {
        $databaseName = $connection->getDatabaseName();

        $result = $connection->select("
            SELECT TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 4) AS size_mb
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND TABLE_TYPE = 'BASE TABLE'
        ", [$databaseName, $tableName]);

        if (empty($result)) {
            return [
                'row_count' => null,
                'size_mb' => null,
            ];
        }

        $row = (array) $result[0];

        return [
            'row_count' => $row['TABLE_ROWS'] !== null ? (int) $row['TABLE_ROWS'] : null,
            'size_mb' => $row['size_mb'] !== null ? round((float) $row['size_mb'], 4) : null,
        ];
    }
}
