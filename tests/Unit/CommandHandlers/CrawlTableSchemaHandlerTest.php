<?php

declare(strict_types=1);

namespace Tests\Unit\CommandHandlers;

use App\Command\CrawlTableSchemaCommand;
use App\Command\ExtractTableDdlCommand;
use App\CommandHandlers\CrawlTableSchemaHandler;
use App\Exceptions\DatabaseConnectionException;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use App\Models\Tenant;
use Exception;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CrawlTableSchemaHandlerTest extends TestCase
{
    private DynamicDatabaseConnector $connector;

    private DomainCommandBus $commandBus;

    private CrawlTableSchemaHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->commandBus = Mockery::mock(DomainCommandBus::class);
        $this->handler = new CrawlTableSchemaHandler($this->connector, $this->commandBus);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_crawls_tables_and_dispatches_extract_commands(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->mysql()->create([
            'tenant_id' => $tenant->id,
        ]);

        $connectionName = 'dynamic_test123';
        $mockConnection = Mockery::mock(Connection::class);

        $this->connector->shouldReceive('databaseNameFromDsn')
            ->once()
            ->andReturn('analytics');

        $this->connector->shouldReceive('createTemporaryConnection')
            ->once()
            ->andReturn($connectionName);

        $this->connector->shouldReceive('listTables')
            ->once()
            ->with($mockConnection)
            ->andReturn(['users', 'orders']);

        $this->connector->shouldReceive('purgeConnection')
            ->once()
            ->with($connectionName);

        // Mock DB::connection to return our mock
        \Illuminate\Support\Facades\DB::shouldReceive('connection')
            ->with($connectionName)
            ->andReturn($mockConnection);

        $this->commandBus->shouldReceive('dispatch')
            ->twice()
            ->with(Mockery::on(function (ExtractTableDdlCommand $cmd) use ($connectionName) {
                return $cmd->getConnectionName() === $connectionName;
            }));

        $command = new CrawlTableSchemaCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->tablesProcessed);
        $this->assertContains('users', $response->tablesProcessed);
        $this->assertContains('orders', $response->tablesProcessed);
        $this->assertEmpty($response->failures);
    }

    #[Test]
    public function it_returns_failure_when_connection_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->mysql()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->connector->shouldReceive('databaseNameFromDsn')
            ->once()
            ->andReturn('analytics');

        $this->connector->shouldReceive('createTemporaryConnection')
            ->once()
            ->andThrow(new DatabaseConnectionException('Connection refused'));

        $command = new CrawlTableSchemaCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = ($this->handler)($command);

        $this->assertFalse($response->isSuccess());
        $this->assertNotEmpty($response->errorMessage);
        $this->assertStringContainsString('Connection refused', $response->errorMessage);
    }

    #[Test]
    public function it_continues_on_individual_table_failures(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->mysql()->create([
            'tenant_id' => $tenant->id,
        ]);

        $connectionName = 'dynamic_test456';
        $mockConnection = Mockery::mock(Connection::class);

        $this->connector->shouldReceive('databaseNameFromDsn')
            ->once()
            ->andReturn('analytics');

        $this->connector->shouldReceive('createTemporaryConnection')
            ->once()
            ->andReturn($connectionName);

        $this->connector->shouldReceive('listTables')
            ->once()
            ->with($mockConnection)
            ->andReturn(['users', 'broken_table', 'orders']);

        $this->connector->shouldReceive('purgeConnection')
            ->once()
            ->with($connectionName);

        \Illuminate\Support\Facades\DB::shouldReceive('connection')
            ->with($connectionName)
            ->andReturn($mockConnection);

        $this->commandBus->shouldReceive('dispatch')
            ->with(Mockery::on(fn (ExtractTableDdlCommand $cmd) => $cmd->getTableName() === 'users'))
            ->once();

        $this->commandBus->shouldReceive('dispatch')
            ->with(Mockery::on(fn (ExtractTableDdlCommand $cmd) => $cmd->getTableName() === 'broken_table'))
            ->once()
            ->andThrow(new Exception('DDL extraction failed'));

        $this->commandBus->shouldReceive('dispatch')
            ->with(Mockery::on(fn (ExtractTableDdlCommand $cmd) => $cmd->getTableName() === 'orders'))
            ->once();

        $command = new CrawlTableSchemaCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->tablesProcessed);
        $this->assertCount(1, $response->failures);
        $this->assertEquals('broken_table', $response->failures[0]['table_name']);
    }

    #[Test]
    public function it_throws_exception_when_datasource_not_found(): void
    {
        $tenant = Tenant::factory()->create();

        $command = new CrawlTableSchemaCommand(tenantId: $tenant->id, datasourceId: 99999);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        ($this->handler)($command);
    }

    #[Test]
    public function it_throws_exception_when_datasource_belongs_to_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $command = new CrawlTableSchemaCommand(tenantId: $tenant1->id, datasourceId: $datasource->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        ($this->handler)($command);
    }
}
