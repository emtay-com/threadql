<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Table;

use App\Models\MasterAdmin;
use App\Models\Table;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateTableTest extends TestCase
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
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
            'priority' => 5,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test that priority can be updated and returns 204.
     */
    public function test_it_updates_priority_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'priority' => 3,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 8,
            ]);

        $response->assertStatus(204);
        $response->assertNoContent();

        $table->refresh();
        $this->assertEquals(8, $table->priority);
    }

    /**
     * Test that priority is required.
     */
    public function test_it_requires_priority(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    /**
     * Test that priority must be an integer.
     */
    public function test_it_validates_priority_is_integer(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 'high',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    /**
     * Test that priority must be between 0 and 10.
     */
    public function test_it_validates_priority_range(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Test priority too low
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => -1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);

        // Test priority too high
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 101,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    /**
     * Test that priority can be set to boundary values.
     */
    public function test_it_accepts_boundary_priority_values(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Test priority = 0
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 0,
            ]);

        $response->assertStatus(204);
        $table->refresh();
        $this->assertEquals(0, $table->priority);

        // Test priority = 10
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 10,
            ]);

        $response->assertStatus(204);
        $table->refresh();
        $this->assertEquals(10, $table->priority);
    }

    /**
     * Test that 404 is returned for non-existent table.
     */
    public function test_it_returns_404_for_nonexistent_table(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/99999", [
                'priority' => 5,
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that 404 is returned for table belonging to different tenant.
     */
    public function test_it_returns_404_for_table_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Try to update tenant2's table via tenant1's route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant1->id}/tables/{$table->id}", [
                'priority' => 5,
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that other fields are not updated.
     */
    public function test_it_only_updates_priority(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'original_name',
            'row_count' => 1000,
            'priority' => 3,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}", [
                'priority' => 8,
                'name' => 'new_name',
                'row_count' => 9999,
            ]);

        $response->assertStatus(204);

        $table->refresh();
        $this->assertEquals(8, $table->priority);
        $this->assertEquals('original_name', $table->name);
        $this->assertEquals(1000, $table->row_count);
    }
}
