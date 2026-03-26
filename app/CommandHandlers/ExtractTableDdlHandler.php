<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\ExtractTableDdlCommand;
use App\Command\ExtractTableDdlResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Command\DomainCommandResponse;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use App\Models\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handler for extracting table DDL information.
 */
class ExtractTableDdlHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
    ) {
    }

    /**
     * Handle the ExtractTableDdlCommand.
     */
    public function __invoke(ExtractTableDdlCommand $command): DomainCommandResponse
    {
        $datasource = $this->findDatasourceOrFail($command->getDatasourceId());
        $result = $this->fetchDdlAndMetadata($datasource, $command);

        $table = $this->upsertTable($command, $result['ddl'], $result['metadata']);

        $this->logAction($table, $command);

        return new ExtractTableDdlResponse(true, [], $table);
    }

    /**
     * Find datasource or fail.
     */
    private function findDatasourceOrFail(int $datasourceId): Datasource
    {
        return Datasource::findOrFail($datasourceId);
    }

    /**
     * Fetch DDL and metadata, reusing an existing connection when provided.
     *
     * @return array{ddl: string, metadata: array{row_count: ?int, size_mb: ?float}}
     */
    private function fetchDdlAndMetadata(Datasource $datasource, ExtractTableDdlCommand $command): array
    {
        $connectionName = $command->getConnectionName();

        if ($connectionName !== null) {
            $connection = DB::connection($connectionName);

            return [
                'ddl' => $this->connector->getCreateTableDdl($connection, $command->getTableName()),
                'metadata' => $this->connector->getTableMetadata($connection, $command->getTableName()),
            ];
        }

        return $this->connector->withConnection($datasource, function ($connection) use ($command) {
            return [
                'ddl' => $this->connector->getCreateTableDdl($connection, $command->getTableName()),
                'metadata' => $this->connector->getTableMetadata($connection, $command->getTableName()),
            ];
        });
    }

    /**
     * Perform idempotent upsert of table.
     *
     * @param array{row_count: ?int, size_mb: ?float} $metadata
     */
    private function upsertTable(ExtractTableDdlCommand $command, string $ddlSql, array $metadata): Table
    {
        /** @var Table $upsertedTable */
        $upsertedTable = Table::withTrashed()->updateOrCreate(
            [
                'tenant_id' => $command->getTenantId(),
                'schema_name' => $command->getSchemaName(),
                'name' => $command->getTableName(),
            ],
            [
                'ddl_sql' => $ddlSql,
                'row_count' => $command->getRowCount() ?? $metadata['row_count'],
                'size_mb' => $command->getSizeMb() ?? $metadata['size_mb'],
            ]
        );

        return $upsertedTable;
    }

    /**
     * Log the action performed.
     */
    private function logAction(Table $table, ExtractTableDdlCommand $command): void
    {
        $action = $table->wasRecentlyCreated ? 'created' : 'updated';
        Log::info("Table DDL {$action}", [
            'tenant_id' => $command->getTenantId(),
            'schema_name' => $command->getSchemaName(),
            'table_name' => $command->getTableName(),
            'action' => $action,
        ]);
    }
}
