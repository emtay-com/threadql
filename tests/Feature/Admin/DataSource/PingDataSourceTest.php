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

class PingDataSourceTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/ping");

        $response->assertStatus(401);
    }

    public function test_it_returns_success_when_connection_works(): void
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

        $mockConnector = Mockery::mock(DynamicDatabaseConnector::class);
        $mockConnector->shouldReceive('withConnection')
            ->once()
            ->withArgs(function (Datasource $ds, callable $callback) use ($datasource, $mockConnection) {
                if ($ds->id !== $datasource->id) {
                    return false;
                }
                $callback($mockConnection);

                return true;
            });

        $this->app->instance(DynamicDatabaseConnector::class, $mockConnector);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/ping");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'connected' => true,
            ],
        ]);
    }

    public function test_it_returns_error_when_connection_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $mockConnector = Mockery::mock(DynamicDatabaseConnector::class);
        $mockConnector->shouldReceive('withConnection')
            ->once()
            ->andThrow(new \App\Exceptions\DatabaseConnectionException('Connection refused'));

        $this->app->instance(DynamicDatabaseConnector::class, $mockConnector);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}/ping");

        $response->assertStatus(422);
        $response->assertJson([
            'data' => [
                'connected' => false,
            ],
        ]);
    }

    public function test_it_returns_404_for_nonexistent_datasource(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources/99999/ping");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/datasources/1/ping');

        $response->assertStatus(404);
    }
}
