<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Table;

use App\Models\MasterAdmin;
use App\Models\Table;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RestoreTableTest extends TestCase
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
        $table = Table::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->patchJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}");

        $response->assertStatus(401);
    }

    /**
     * Test that a soft deleted table can be restored and returns 204.
     */
    public function test_it_restores_table_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
            'name' => 'users',
        ]);

        $tableId = $table->id;

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Verify table is soft deleted
        $this->assertNotNull($table->deleted_at);
        $this->assertDatabaseHas('tables', [
            'id' => $tableId,
            'deleted_at' => $table->deleted_at,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}");

        $response->assertStatus(204);
        $response->assertNoContent();

        // Verify table is restored (deleted_at is null)
        $this->assertDatabaseHas('tables', [
            'id' => $tableId,
            'deleted_at' => null,
        ]);
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
            ->patchJson("/api/admin/tenants/{$tenant->id}/tables/99999");

        $response->assertStatus(404);
    }

    /**
     * Test that 404 is returned for table belonging to different tenant.
     */
    public function test_it_returns_404_for_table_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $table = Table::factory()->trashed()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Try to restore tenant2's table via tenant1's route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant1->id}/tables/{$table->id}");

        $response->assertStatus(404);

        // Verify table is still soft deleted
        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
        ]);
        $this->assertNotNull(Table::withTrashed()->find($table->id)->deleted_at);
    }

    /**
     * Test that 404 is returned for non-existent tenant.
     */
    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/tenants/99999/tables/1');

        $response->assertStatus(404);
    }

    /**
     * Test that restoring one table does not affect other tables.
     */
    public function test_it_only_restores_specified_table(): void
    {
        $tenant = Tenant::factory()->create();
        $table1 = Table::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
            'name' => 'users',
        ]);
        $table2 = Table::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
            'name' => 'orders',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/tables/{$table1->id}");

        $response->assertStatus(204);

        // Verify table1 is restored
        $this->assertNull(Table::find($table1->id)->deleted_at);

        // Verify table2 is still soft deleted
        $this->assertNotNull(Table::withTrashed()->find($table2->id)->deleted_at);
    }

    /**
     * Test that restoring an already active (not soft deleted) table works.
     */
    public function test_it_handles_restoring_non_deleted_table(): void
    {
        $tenant = Tenant::factory()->create();
        $table = Table::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'users',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/tables/{$table->id}");

        $response->assertStatus(204);

        // Verify table is still active
        $this->assertNull(Table::find($table->id)->deleted_at);
    }
}
