<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\TestDatasourceConnectionCommand;
use App\Command\TestDatasourceConnectionResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use Exception;

class TestDatasourceConnectionHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
    ) {
    }

    /**
     * Test a datasource connection by executing SELECT 1.
     */
    public function __invoke(TestDatasourceConnectionCommand $command): TestDatasourceConnectionResponse
    {
        $datasource = Datasource::where('tenant_id', $command->tenantId)
            ->where('id', $command->datasourceId)
            ->firstOrFail();

        try {
            $this->connector->withConnection($datasource, function ($connection) {
                $connection->selectOne('SELECT 1');
            });

            return TestDatasourceConnectionResponse::success();
        } catch (Exception $e) {
            return TestDatasourceConnectionResponse::failed($e->getMessage());
        }
    }
}
