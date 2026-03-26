<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Table;

use App\Models\MasterAdmin;
use App\Models\Table;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListTablesTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/tables");

        $response->assertStatus(401);
    }

    /**
     * Test that authenticated requests can list tables for a tenant.
     */
    public function test_it_lists_all_tables_for_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
        ]);

        $table1 = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'users',
            'priority' => 8,
            'row_count' => 5000,
        ]);

        $table2 = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'orders',
            'priority' => 9,
            'row_count' => 150000,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/tables");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'tenant_id', 'name', 'priority', 'row_count', 'created_at', 'deleted_at'],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $t1Data = collect($data)
            ->firstWhere('id', $table1->id);
        $this->assertNotNull($t1Data);
        $this->assertEquals('users', $t1Data['name']);
        $this->assertEquals($tenant->id, $t1Data['tenant_id']);
        $this->assertEquals(8, $t1Data['priority']);
        $this->assertEquals(5000, $t1Data['row_count']);

        $t2Data = collect($data)
            ->firstWhere('id', $table2->id);
        $this->assertNotNull($t2Data);
        $this->assertEquals('orders', $t2Data['name']);
        $this->assertEquals(9, $t2Data['priority']);
        $this->assertEquals(150000, $t2Data['row_count']);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    /**
     * Test that tables are scoped to the requested tenant.
     */
    public function test_it_only_returns_tables_for_requested_tenant(): void
    {
        $tenant1 = Tenant::factory()->create([
            'name' => 'Tenant One',
        ]);
        $tenant2 = Tenant::factory()->create([
            'name' => 'Tenant Two',
        ]);

        $table1 = Table::factory()->create([
            'tenant_id' => $tenant1->id,
            'name' => 'tenant1_users',
        ]);

        $table2 = Table::factory()->create([
            'tenant_id' => $tenant2->id,
            'name' => 'tenant2_users',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/tables");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($table1->id, $data[0]['id']);
        $this->assertEquals('tenant1_users', $data[0]['name']);

        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant2->id}/tables");

        $response2->assertStatus(200);

        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals($table2->id, $data2[0]['id']);
        $this->assertEquals('tenant2_users', $data2[0]['name']);
    }

    /**
     * Test that tables are ordered by priority descending, then name ascending.
     */
    public function test_it_orders_tables_by_priority_desc_name_asc(): void
    {
        $tenant = Tenant::factory()->create();

        $lowPriority = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'alpha_table',
            'priority' => 5,
        ]);
        $highPriority = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'beta_table',
            'priority' => 10,
        ]);
        $samePriorityA = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'aardvark_table',
            'priority' => 10,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/tables");

        $response->assertStatus(200);

        $data = $response->json('data');

        // priority 10 first (sorted by name asc within same priority)
        $this->assertEquals($samePriorityA->id, $data[0]['id']);
        $this->assertEquals($highPriority->id, $data[1]['id']);
        // priority 5 last
        $this->assertEquals($lowPriority->id, $data[2]['id']);
    }

    /**
     * Test that requesting tables for non-existent tenant returns 404.
     */
    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/tables');

        $response->assertStatus(404);
    }

    /**
     * Test that empty tables list returns empty array.
     */
    public function test_it_returns_empty_array_when_no_tables(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/tables");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ]);
    }
}
