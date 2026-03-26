<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use App\Exceptions\TableNotFoundException;
use Illuminate\Database\Connection;

/**
 * PostgreSQL-specific schema introspection using information_schema and pg_catalog.
 */
class PostgresSchemaStrategy implements SchemaIntrospectionStrategy
{
    private const DEFAULT_SCHEMA = 'public';

    /**
     * List base tables using information_schema.tables for PostgreSQL.
     *
     * @return array<string>
     */
    public function listTables(Connection $connection): array
    {
        $tables = $connection->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = ?
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ", [self::DEFAULT_SCHEMA]);

        return array_column($tables, 'table_name');
    }

    /**
     * Get DDL by reconstructing CREATE TABLE from information_schema and pg_catalog.
     *
     * PostgreSQL doesn't have a direct SHOW CREATE TABLE equivalent,
     * so we reconstruct the DDL from column metadata and constraints.
     */
    public function getCreateTableDdl(Connection $connection, string $tableName): string
    {
        $columns = $this->getColumns($connection, $tableName);

        if (empty($columns)) {
            throw new TableNotFoundException("Table '{$tableName}' not found or not accessible");
        }

        $constraints = $this->getConstraints($connection, $tableName);
        $indexes = $this->getIndexes($connection, $tableName);

        return $this->buildDdl($tableName, $columns, $constraints, $indexes);
    }

    /**
     * Get column definitions for a table.
     *
     * @return array<array{column_name: string, data_type: string, is_nullable: string, column_default: ?string, character_maximum_length: ?int}>
     */
    private function getColumns(Connection $connection, string $tableName): array
    {
        return $connection->select('
            SELECT
                c.column_name,
                c.data_type,
                c.is_nullable,
                c.column_default,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale
            FROM information_schema.columns c
            WHERE c.table_schema = ?
            AND c.table_name = ?
            ORDER BY c.ordinal_position
        ', [self::DEFAULT_SCHEMA, $tableName]);
    }

    /**
     * Get table constraints (primary keys, unique, foreign keys).
     */
    private function getConstraints(Connection $connection, string $tableName): array
    {
        return $connection->select('
            SELECT
                tc.constraint_type,
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            LEFT JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_schema = ?
            AND tc.table_name = ?
            ORDER BY tc.constraint_type, kcu.ordinal_position
        ', [self::DEFAULT_SCHEMA, $tableName]);
    }

    /**
     * Get indexes for a table.
     */
    private function getIndexes(Connection $connection, string $tableName): array
    {
        return $connection->select('
            SELECT
                i.relname AS index_name,
                ix.indisunique AS is_unique,
                a.attname AS column_name
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = ?
            AND t.relname = ?
            AND NOT ix.indisprimary
            ORDER BY i.relname, a.attnum
        ', [self::DEFAULT_SCHEMA, $tableName]);
    }

    /**
     * Build the DDL string from column/constraint/index metadata.
     */
    private function buildDdl(string $tableName, array $columns, array $constraints, array $indexes): string
    {
        $lines = [];

        // Column definitions
        foreach ($columns as $col) {
            $col = (array) $col;
            $line = "    {$col['column_name']} {$this->formatColumnType($col)}";

            if ($col['is_nullable'] === 'NO') {
                $line .= ' NOT NULL';
            }

            if ($col['column_default'] !== null) {
                $line .= " DEFAULT {$col['column_default']}";
            }

            $lines[] = $line;
        }

        // Primary key constraints
        $pkColumns = [];
        $uniqueGroups = [];
        foreach ($constraints as $constraint) {
            $constraint = (array) $constraint;
            if ($constraint['constraint_type'] === 'PRIMARY KEY') {
                $pkColumns[] = $constraint['column_name'];
            } elseif ($constraint['constraint_type'] === 'UNIQUE') {
                $uniqueGroups[$constraint['constraint_name']][] = $constraint['column_name'];
            }
        }
        if (! empty($pkColumns)) {
            $lines[] = '    PRIMARY KEY ('.implode(', ', $pkColumns).')';
        }

        // Unique constraints
        foreach ($uniqueGroups as $constraintName => $uniqueColumns) {
            $lines[] = "    CONSTRAINT {$constraintName} UNIQUE (".implode(', ', $uniqueColumns).')';
        }

        // Foreign key constraints
        $fkGroups = [];
        foreach ($constraints as $constraint) {
            $constraint = (array) $constraint;
            if ($constraint['constraint_type'] === 'FOREIGN KEY') {
                $fkGroups[$constraint['constraint_name']][] = $constraint;
            }
        }
        foreach ($fkGroups as $fkName => $fkColumns) {
            $cols = implode(', ', array_column($fkColumns, 'column_name'));
            $refTable = $fkColumns[0]['foreign_table_name'];
            $refCols = implode(', ', array_column($fkColumns, 'foreign_column_name'));
            $lines[] = "    CONSTRAINT {$fkName} FOREIGN KEY ({$cols}) REFERENCES {$refTable} ({$refCols})";
        }

        // Indexes (non-primary, non-unique constraint indexes)
        $indexGroups = [];
        foreach ($indexes as $index) {
            $index = (array) $index;
            $indexGroups[$index['index_name']][] = $index;
        }
        foreach ($indexGroups as $indexName => $indexColumns) {
            $cols = implode(', ', array_column($indexColumns, 'column_name'));
            $unique = ! empty($indexColumns[0]['is_unique']) ? 'UNIQUE ' : '';
            $lines[] = "    -- {$unique}INDEX {$indexName} ({$cols})";
        }

        $ddl = "CREATE TABLE {$tableName} (\n";
        $ddl .= implode(",\n", $lines);
        $ddl .= "\n)";

        return $ddl;
    }

    /**
     * Get table metadata using pg_class and pg_total_relation_size.
     *
     * @return array{row_count: ?int, size_mb: ?float}
     */
    public function getTableMetadata(Connection $connection, string $tableName): array
    {
        $result = $connection->select('
            SELECT
                c.reltuples::bigint AS row_count,
                ROUND(pg_total_relation_size(c.oid)::numeric / 1048576, 4) AS size_mb
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ?
            AND c.relname = ?
            AND c.relkind = ?
        ', [self::DEFAULT_SCHEMA, $tableName, 'r']);

        if (empty($result)) {
            return [
                'row_count' => null,
                'size_mb' => null,
            ];
        }

        $row = (array) $result[0];

        return [
            'row_count' => $row['row_count'] !== null ? max(0, (int) $row['row_count']) : null,
            'size_mb' => $row['size_mb'] !== null ? round((float) $row['size_mb'], 4) : null,
        ];
    }

    /**
     * Format a PostgreSQL column type from metadata.
     */
    private function formatColumnType(array $col): string
    {
        $type = $col['data_type'];

        if ($col['character_maximum_length'] !== null) {
            return "{$type}({$col['character_maximum_length']})";
        }

        if ($type === 'numeric' && $col['numeric_precision'] !== null) {
            $precision = $col['numeric_precision'];
            $scale = $col['numeric_scale'] ?? 0;

            return "numeric({$precision},{$scale})";
        }

        return $type;
    }
}
