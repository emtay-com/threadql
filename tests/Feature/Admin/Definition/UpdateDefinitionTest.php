<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Definition;

use App\Models\Definition;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateDefinitionTest extends TestCase
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
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", [
            'definition' => 'Updated definition text',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test that definition can be updated and returns 204.
     */
    public function test_it_updates_definition_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'ARR',
            'definition' => 'Annual Recurring Revenue',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", [
                'definition' => 'Annual Recurring Revenue - total value of recurring revenue per year',
            ]);

        $response->assertStatus(204);
        $response->assertNoContent();

        $definition->refresh();
        $this->assertEquals(
            'Annual Recurring Revenue - total value of recurring revenue per year',
            $definition->definition
        );
    }

    /**
     * Test that definition field is required.
     */
    public function test_it_requires_definition(): void
    {
        $tenant = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['definition']);
    }

    /**
     * Test that definition must be a string.
     */
    public function test_it_validates_definition_is_string(): void
    {
        $tenant = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", [
                'definition' => 12345,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['definition']);
    }

    /**
     * Test that definition has max length.
     */
    public function test_it_validates_definition_max_length(): void
    {
        $tenant = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", [
                'definition' => str_repeat('a', 1001),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['definition']);
    }

    /**
     * Test that 404 is returned for non-existent definition.
     */
    public function test_it_returns_404_for_nonexistent_definition(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/99999", [
                'definition' => 'Updated definition',
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that 404 is returned for definition belonging to different tenant.
     */
    public function test_it_returns_404_for_definition_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant2->id,
            'definition' => 'Original definition',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Try to update tenant2's definition via tenant1's route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant1->id}/definitions/{$definition->id}", [
                'definition' => 'Attempted update',
            ]);

        $response->assertStatus(404);

        // Verify definition was not changed
        $definition->refresh();
        $this->assertEquals('Original definition', $definition->definition);
    }

    /**
     * Test that only definition field is updated.
     */
    public function test_it_only_updates_definition_field(): void
    {
        $tenant = Tenant::factory()->create();
        $definition = Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'subject' => 'Original Subject',
            'definition' => 'Original definition',
            'priority' => 5,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/definitions/{$definition->id}", [
                'definition' => 'Updated definition',
                'subject' => 'New Subject',
                'priority' => 10,
            ]);

        $response->assertStatus(204);

        $definition->refresh();
        $this->assertEquals('Updated definition', $definition->definition);
        $this->assertEquals('Original Subject', $definition->subject);
        $this->assertEquals(5, $definition->priority);
    }
}
