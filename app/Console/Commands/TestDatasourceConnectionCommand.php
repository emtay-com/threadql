<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Command\TestDatasourceConnectionCommand as DomainTestCommand;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Datasource;
use Illuminate\Console\Command;

class TestDatasourceConnectionCommand extends Command
{
    protected $signature = 'datasource:test {datasource_id}';

    protected $description = 'Test a datasource connection by executing SELECT 1';

    public function __construct(
        private readonly DomainCommandBus $commandBus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $datasourceId = (int) $this->argument('datasource_id');
        $datasource = Datasource::find($datasourceId);

        if (! $datasource) {
            $this->error("Datasource with ID {$datasourceId} not found.");

            return self::FAILURE;
        }

        $this->info("Testing connection for datasource: {$datasource->label} (ID: {$datasource->id})");
        $this->info("Tenant ID: {$datasource->tenant_id}");

        if ($datasource->use_ssh) {
            $this->info("SSH tunnel: {$datasource->ssh_username}@{$datasource->ssh_host}:{$datasource->ssh_port}");
        }

        $command = new DomainTestCommand(tenantId: $datasource->tenant_id, datasourceId: $datasource->id);

        $response = $this->commandBus->dispatch($command);

        if ($response->isSuccess()) {
            $this->info('Connection successful! SELECT 1 executed without errors.');

            return self::SUCCESS;
        }

        $this->error('Connection failed!');
        foreach ($response->getErrors() as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }
}
