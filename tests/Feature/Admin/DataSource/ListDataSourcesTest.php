<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\DataSource;

use App\Models\Datasource;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListDataSourcesTest extends TestCase
{
    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure JWT secret is set for tests
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    /**
     * Test that unauthenticated requests are rejected.
     */
    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/datasources");

        $response->assertStatus(401);
    }

    /**
     * Test that authenticated requests can list datasources for a tenant.
     */
    public function test_it_lists_all_datasources_for_tenant(): void
    {
        // Create tenant
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
        ]);

        // Create datasources for this tenant
        $datasource1 = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Production DB',
            'dsn' => 'mysql://user:pass@localhost:3306/prod',
            'default_limit' => 100,
            'query_timeout_seconds' => 30,
            'timezone' => 'UTC',
            'allowed_schemas_json' => ['public', 'analytics'],
        ]);

        $datasource2 = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Analytics DB',
            'dsn' => 'mysql://user:pass@localhost:3306/analytics',
            'default_limit' => 500,
            'query_timeout_seconds' => 60,
            'timezone' => 'America/New_York',
            'allowed_schemas_json' => null,
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Make authenticated request
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources");

        $response->assertStatus(200);

        // Assert response structure
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'tenant_id',
                    'label',
                    'has_dsn',
                    'allowed_schemas',
                    'default_limit',
                    'query_timeout_seconds',
                    'timezone',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => ['total'],
        ]);

        // Assert data content
        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Find datasource1 in response
        $ds1Data = collect($data)
            ->firstWhere('id', $datasource1->id);
        $this->assertNotNull($ds1Data);
        $this->assertEquals('Production DB', $ds1Data['label']);
        $this->assertEquals($tenant->id, $ds1Data['tenant_id']);
        $this->assertTrue($ds1Data['has_dsn']);
        $this->assertEquals(['public', 'analytics'], $ds1Data['allowed_schemas']);
        $this->assertEquals(100, $ds1Data['default_limit']);
        $this->assertEquals(30, $ds1Data['query_timeout_seconds']);
        $this->assertEquals('UTC', $ds1Data['timezone']);

        // Find datasource2 in response
        $ds2Data = collect($data)
            ->firstWhere('id', $datasource2->id);
        $this->assertNotNull($ds2Data);
        $this->assertEquals('Analytics DB', $ds2Data['label']);
        $this->assertEquals(500, $ds2Data['default_limit']);
        $this->assertEquals(60, $ds2Data['query_timeout_seconds']);
        $this->assertEquals('America/New_York', $ds2Data['timezone']);
        $this->assertNull($ds2Data['allowed_schemas']);

        // Assert meta
        $this->assertEquals(2, $response->json('meta.total'));
    }

    /**
     * Test that DSN is not exposed in the response.
     */
    public function test_it_does_not_expose_dsn(): void
    {
        $tenant = Tenant::factory()->create();

        // Create datasource with DSN
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Secret DB',
            'dsn' => 'mysql://secret_user:secret_pass@localhost:3306/secretdb',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Make authenticated request
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources");

        $response->assertStatus(200);

        $data = $response->json('data');
        $dsData = collect($data)
            ->firstWhere('id', $datasource->id);

        // Assert DSN is not in response
        $this->assertArrayNotHasKey('dsn', $dsData);

        // Assert we only get boolean indicator
        $this->assertTrue($dsData['has_dsn']);
    }

    /**
     * Test that datasources are scoped to the requested tenant.
     */
    public function test_it_only_returns_datasources_for_requested_tenant(): void
    {
        // Create two tenants
        $tenant1 = Tenant::factory()->create([
            'name' => 'Tenant One',
        ]);
        $tenant2 = Tenant::factory()->create([
            'name' => 'Tenant Two',
        ]);

        // Create datasources for each tenant
        $ds1 = Datasource::factory()->create([
            'tenant_id' => $tenant1->id,
            'label' => 'Tenant 1 DB',
        ]);

        $ds2 = Datasource::factory()->create([
            'tenant_id' => $tenant2->id,
            'label' => 'Tenant 2 DB',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Request datasources for tenant1
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/datasources");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($ds1->id, $data[0]['id']);
        $this->assertEquals('Tenant 1 DB', $data[0]['label']);

        // Request datasources for tenant2
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant2->id}/datasources");

        $response2->assertStatus(200);

        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals($ds2->id, $data2[0]['id']);
        $this->assertEquals('Tenant 2 DB', $data2[0]['label']);
    }

    /**
     * Test that datasources are ordered by created_at descending.
     */
    public function test_it_orders_datasources_by_created_at_desc(): void
    {
        $tenant = Tenant::factory()->create();

        // Create datasources with specific timestamps
        $older = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Older DB',
        ]);
        $this->travel(1)
            ->hours();
        $newer = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Newer DB',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Make authenticated request
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources");

        $response->assertStatus(200);

        $data = $response->json('data');

        // Assert newer datasource comes first
        $this->assertEquals($newer->id, $data[0]['id']);
        $this->assertEquals($older->id, $data[1]['id']);
    }

    /**
     * Test that requesting datasources for non-existent tenant returns 404.
     */
    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Make authenticated request for non-existent tenant
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/datasources');

        $response->assertStatus(404);
    }

    /**
     * Test that empty datasources list returns empty array.
     */
    public function test_it_returns_empty_array_when_no_datasources(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Make authenticated request
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/datasources");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ]);
    }
}
