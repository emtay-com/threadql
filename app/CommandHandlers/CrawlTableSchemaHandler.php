<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\CrawlTableSchemaCommand;
use App\Command\CrawlTableSchemaResponse;
use App\Command\ExtractTableDdlCommand;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrawlTableSchemaHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
        private readonly DomainCommandBus $commandBus,
    ) {
    }

    /**
     * Crawl a datasource to discover tables and extract their DDL.
     */
    public function __invoke(CrawlTableSchemaCommand $command): CrawlTableSchemaResponse
    {
        $datasource = Datasource::where('tenant_id', $command->tenantId)
            ->where('id', $command->datasourceId)
            ->firstOrFail();

        $schemaName = $this->connector->databaseNameFromDsn($datasource->dsn);

        Log::info('Starting table schema crawl', [
            'datasource_id' => $command->datasourceId,
            'schema_name' => $schemaName,
        ]);

        $connectionName = null;

        try {
            $connectionName = $this->connector->createTemporaryConnection($datasource);
            $connection = DB::connection($connectionName);
            $tables = $this->connector->listTables($connection);
        } catch (Exception $e) {
            if ($connectionName !== null) {
                $this->connector->purgeConnection($connectionName);
            }

            Log::error('Failed to enumerate tables', [
                'datasource_id' => $command->datasourceId,
                'error' => $e->getMessage(),
            ]);

            return CrawlTableSchemaResponse::failed($e->getMessage());
        }

        $processed = [];
        $failures = [];

        try {
            foreach ($tables as $tableName) {
                try {
                    $ddlCommand = new ExtractTableDdlCommand(
                        tenantId: $command->tenantId,
                        datasourceId: $command->datasourceId,
                        schemaName: $schemaName,
                        tableName: $tableName,
                        connectionName: $connectionName,
                    );

                    $this->commandBus->dispatch($ddlCommand);
                    $processed[] = $tableName;
                } catch (Exception $e) {
                    $failures[] = [
                        'table_name' => $tableName,
                        'error' => $e->getMessage(),
                    ];

                    Log::warning('Failed to extract DDL for table', [
                        'datasource_id' => $command->datasourceId,
                        'table_name' => $tableName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->connector->purgeConnection($connectionName);
        }

        Log::info('Completed table schema crawl', [
            'datasource_id' => $command->datasourceId,
            'schema_name' => $schemaName,
            'total_tables' => count($tables),
            'success_count' => count($processed),
            'failure_count' => count($failures),
        ]);

        return CrawlTableSchemaResponse::completed($processed, $failures);
    }
}
