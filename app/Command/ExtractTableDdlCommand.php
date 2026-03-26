<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to extract table DDL information.
 */
class ExtractTableDdlCommand implements DomainCommand
{
    /**
     * @param int $tenantId The tenant ID
     * @param int $datasourceId The datasource ID
     * @param string $schemaName The schema/database name
     * @param string $tableName The table name
     * @param int|null $rowCount The row count (optional)
     * @param float|null $sizeMb The table size in megabytes (optional)
     * @param string|null $connectionName Pre-established connection name to reuse
     */
    public function __construct(
        private readonly int $tenantId,
        private readonly int $datasourceId,
        private readonly string $schemaName,
        private readonly string $tableName,
        private readonly ?int $rowCount = null,
        private readonly ?float $sizeMb = null,
        private readonly ?string $connectionName = null,
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getDatasourceId(): int
    {
        return $this->datasourceId;
    }

    public function getSchemaName(): string
    {
        return $this->schemaName;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function getSizeMb(): ?float
    {
        return $this->sizeMb;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }
}
