<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Command\ExtractTableDdlCommand;
use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Datasource;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to crawl table schemas and extract DDL information.
 */
class TableSchemaCrawlerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 60; // 1 minute

    #[Assignable]
    private DynamicDatabaseConnector $connector;

    #[Assignable]
    private DomainCommandBus $commandBus;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new FailOnUnrecoverableException];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $datasourceId,
        private readonly ?string $dsnOverride = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(DynamicDatabaseConnector $connector, DomainCommandBus $commandBus): void
    {
        $this->assignParams(func_get_args());

        $datasource = $this->findDatasourceOrFail($this->datasourceId);

        // Use DSN override if provided
        if ($this->dsnOverride !== null) {
            $datasource->dsn = $this->dsnOverride;
        }

        $schemaName = $this->connector->databaseNameFromDsn($datasource->dsn);

        Log::info('Starting table extraction job', [
            'datasource_id' => $this->datasourceId,
            'schema_name' => $schemaName,
        ]);

        $successCount = 0;
        $failureCount = 0;
        $failures = [];

        try {
            $tables = $this->connector->withConnection($datasource, function ($connection) {
                return $this->connector->listTables($connection);
            });

            Log::info('Found tables to process', [
                'datasource_id' => $this->datasourceId,
                'table_count' => count($tables),
            ]);

            foreach ($tables as $tableName) {
                try {
                    $command = new ExtractTableDdlCommand(
                        tenantId: $datasource->tenant_id,
                        datasourceId: $this->datasourceId,
                        schemaName: $schemaName,
                        tableName: $tableName,
                    );

                    // Dispatch the command via CommandBus
                    $this->commandBus->dispatch($command);

                    $successCount++;

                    Log::debug('Dispatched table DDL extraction command', [
                        'datasource_id' => $this->datasourceId,
                        'table_name' => $tableName,
                    ]);

                } catch (Exception $e) {
                    $failureCount++;
                    $failures[] = [
                        'table_name' => $tableName,
                        'error' => $e->getMessage(),
                    ];

                    Log::warning('Failed to dispatch table DDL extraction command', [
                        'datasource_id' => $this->datasourceId,
                        'table_name' => $tableName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Exception $e) {
            Log::error('Failed to enumerate tables', [
                'datasource_id' => $this->datasourceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('Completed table extraction job', [
            'datasource_id' => $this->datasourceId,
            'schema_name' => $schemaName,
            'total_tables' => count($tables),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'failures' => $failures,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Table extraction job failed', [
            'datasource_id' => $this->datasourceId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Find datasource or throw EntityNotFoundException.
     */
    private function findDatasourceOrFail(int $datasourceId): Datasource
    {
        $datasource = Datasource::find($datasourceId);
        if (! $datasource) {
            throw new EntityNotFoundException('Datasource', (string) $datasourceId);
        }

        return $datasource;
    }
}
