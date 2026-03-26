<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Definition;

use App\Models\Definition;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListDefinitionsTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/definitions");

        $response->assertStatus(401);
    }

    /**
     * Test that authenticated requests can list definitions for a tenant.
     */
    public function test_it_lists_all_definitions_for_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
        ]);

        $definition1 = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'ARR',
            'definition' => 'Annual Recurring Revenue',
            'priority' => 5,
        ]);

        $definition2 = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'MRR',
            'definition' => 'Monthly Recurring Revenue',
            'priority' => 3,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/definitions");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'tenant_id', 'subject', 'definition', 'priority', 'created_at'],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $d1Data = collect($data)
            ->firstWhere('id', $definition1->id);
        $this->assertNotNull($d1Data);
        $this->assertEquals('ARR', $d1Data['subject']);
        $this->assertEquals('Annual Recurring Revenue', $d1Data['definition']);
        $this->assertEquals($tenant->id, $d1Data['tenant_id']);
        $this->assertEquals(5, $d1Data['priority']);

        $d2Data = collect($data)
            ->firstWhere('id', $definition2->id);
        $this->assertNotNull($d2Data);
        $this->assertEquals('MRR', $d2Data['subject']);
        $this->assertEquals('Monthly Recurring Revenue', $d2Data['definition']);
        $this->assertEquals(3, $d2Data['priority']);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    /**
     * Test that definitions are scoped to the requested tenant.
     */
    public function test_it_only_returns_definitions_for_requested_tenant(): void
    {
        $tenant1 = Tenant::factory()->create([
            'name' => 'Tenant One',
        ]);
        $tenant2 = Tenant::factory()->create([
            'name' => 'Tenant Two',
        ]);

        $definition1 = Definition::factory()->create([
            'tenant_id' => $tenant1->id,
            'subject' => 'Tenant1 Term',
        ]);

        $definition2 = Definition::factory()->create([
            'tenant_id' => $tenant2->id,
            'subject' => 'Tenant2 Term',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/definitions");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($definition1->id, $data[0]['id']);
        $this->assertEquals('Tenant1 Term', $data[0]['subject']);

        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant2->id}/definitions");

        $response2->assertStatus(200);

        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals($definition2->id, $data2[0]['id']);
        $this->assertEquals('Tenant2 Term', $data2[0]['subject']);
    }

    /**
     * Test that definitions are ordered by created_at descending.
     */
    public function test_it_orders_definitions_by_created_at_desc(): void
    {
        $tenant = Tenant::factory()->create();

        $older = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'Older Term',
        ]);
        $this->travel(1)
            ->hours();
        $newer = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'Newer Term',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/definitions");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals($newer->id, $data[0]['id']);
        $this->assertEquals($older->id, $data[1]['id']);
    }

    /**
     * Test that requesting definitions for non-existent tenant returns 404.
     */
    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/definitions');

        $response->assertStatus(404);
    }

    /**
     * Test that empty definitions list returns empty array.
     */
    public function test_it_returns_empty_array_when_no_definitions(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/definitions");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ]);
    }
}
