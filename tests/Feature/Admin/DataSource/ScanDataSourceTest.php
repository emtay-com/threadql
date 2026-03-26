<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\DataSource;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ScanDataSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->postJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/scan");

        $response->assertStatus(401);
    }

    public function test_it_scans_datasource_and_returns_tables(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->mysql()->create([
            'tenant_id' => $tenant->id,
        ]);

        $connectionName = 'dynamic_test_scan';
        $mockConnection = Mockery::mock(Connection::class);

        $mockConnector = Mockery::mock(DynamicDatabaseConnector::class);
        $mockConnector->shouldReceive('databaseNameFromDsn')
            ->andReturn('analytics');

        // CrawlTableSchemaHandler creates one connection for the whole crawl
        $mockConnector->shouldReceive('createTemporaryConnection')
            ->once()
            ->andReturn($connectionName);

        $mockConnector->shouldReceive('listTables')
            ->once()
            ->with($mockConnection)
            ->andReturn(['users', 'orders']);

        $mockConnector->shouldReceive('purgeConnection')
            ->once()
            ->with($connectionName);

        // ExtractTableDdlHandler uses the shared connection for DDL extraction
        $mockConnector->shouldReceive('getCreateTableDdl')
            ->andReturn('CREATE TABLE ...');

        $mockConnector->shouldReceive('getTableMetadata')
            ->andReturn([
                'row_count' => 1000,
                'size_mb' => 1.5,
            ]);

        \Illuminate\Support\Facades\DB::shouldReceive('connection')
            ->with($connectionName)
            ->andReturn($mockConnection);

        $this->app->instance(DynamicDatabaseConnector::class, $mockConnector);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/scan");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'tenant_id', 'priority'],
            ],
            'meta' => ['total'],
        ]);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_it_returns_error_when_connection_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->mysql()->create([
            'tenant_id' => $tenant->id,
        ]);

        $mockConnector = Mockery::mock(DynamicDatabaseConnector::class);
        $mockConnector->shouldReceive('databaseNameFromDsn')
            ->andReturn('analytics');
        $mockConnector->shouldReceive('createTemporaryConnection')
            ->once()
            ->andThrow(new \App\Exceptions\DatabaseConnectionException('Connection refused'));

        $this->app->instance(DynamicDatabaseConnector::class, $mockConnector);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/scan");

        $response->assertStatus(422);
        $response->assertJson([
            'data' => [
                'success' => false,
            ],
        ]);
    }

    public function test_it_returns_404_for_nonexistent_datasource(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources/99999/scan");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants/99999/datasources/1/scan');

        $response->assertStatus(404);
    }
}
