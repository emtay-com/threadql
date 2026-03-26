<?php

declare(strict_types=1);

namespace Tests\Unit\CommandHandlers;

use App\Command\TestDatasourceConnectionCommand;
use App\CommandHandlers\TestDatasourceConnectionHandler;
use App\Exceptions\DatabaseConnectionException;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TestDatasourceConnectionHandlerTest extends TestCase
{
    private DynamicDatabaseConnector $connector;

    private TestDatasourceConnectionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->handler = new TestDatasourceConnectionHandler($this->connector);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_success_when_connection_works(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $mockConnection = Mockery::mock(Connection::class);
        $mockConnection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT 1')
            ->andReturn((object) [
                '1' => 1,
            ]);

        $this->connector->shouldReceive('withConnection')
            ->once()
            ->withArgs(function (Datasource $ds, callable $callback) use ($datasource, $mockConnection) {
                if ($ds->id !== $datasource->id) {
                    return false;
                }
                $callback($mockConnection);

                return true;
            });

        $command = new TestDatasourceConnectionCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->connected);
        $this->assertNull($response->errorMessage);
        $this->assertEmpty($response->getErrors());
    }

    #[Test]
    public function it_returns_failure_when_connection_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->connector->shouldReceive('withConnection')
            ->once()
            ->andThrow(new DatabaseConnectionException('Failed to establish connection: Connection refused'));

        $command = new TestDatasourceConnectionCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = ($this->handler)($command);

        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->connected);
        $this->assertNotEmpty($response->errorMessage);
        $this->assertStringContainsString('Connection refused', $response->errorMessage);
        $this->assertNotEmpty($response->getErrors());
    }

    #[Test]
    public function it_throws_exception_when_datasource_not_found(): void
    {
        $tenant = Tenant::factory()->create();

        $command = new TestDatasourceConnectionCommand(tenantId: $tenant->id, datasourceId: 99999);

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

        $command = new TestDatasourceConnectionCommand(tenantId: $tenant1->id, datasourceId: $datasource->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        ($this->handler)($command);
    }
}
