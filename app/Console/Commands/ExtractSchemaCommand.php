<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TableSchemaCrawlerJob;
use App\Models\Datasource;
use Exception;
use Illuminate\Console\Command;

/**
 * Command to extract and store table DDL from a datasource.
 */
class ExtractSchemaCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'schema:extract {datasource_id} {--dsn-override= : Optional DSN override}';

    /**
     * The console command description.
     */
    protected $description = 'Extract and store table DDL from a datasource';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $datasourceId = (int) $this->argument('datasource_id');
        $dsnOverride = $this->option('dsn-override');

        // Check if datasource exists
        $datasource = Datasource::find($datasourceId);
        if (! $datasource) {
            $this->error("Datasource with ID {$datasourceId} not found.");

            return self::FAILURE;
        }

        $this->info("Starting schema extraction for datasource ID: {$datasourceId}");
        if ($dsnOverride) {
            $this->info("Using DSN override: {$dsnOverride}");
        }

        try {
            // Dispatch the job
            TableSchemaCrawlerJob::dispatchSync($datasourceId, $dsnOverride);

            $this->info('Schema extraction job dispatched successfully.');
            $this->info('Check the queue and logs for progress.');

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Failed to dispatch schema extraction job: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
