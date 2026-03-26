<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Tests\TestCase;

class PingTest extends TestCase
{
    /**
     * Test that ping returns success for valid tenant.
     */
    public function test_it_returns_ping_true_for_valid_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->getJson("/api/{$tenant->uuid}/ping");

        $response->assertStatus(200);
        $response->assertExactJson([
            'ping' => true,
        ]);
    }

    /**
     * Test that ping does not require authentication.
     */
    public function test_it_does_not_require_authentication(): void
    {
        $tenant = Tenant::factory()->create();

        // No auth headers - should still work
        $response = $this->getJson("/api/{$tenant->uuid}/ping");

        $response->assertStatus(200);
        $response->assertJson([
            'ping' => true,
        ]);
    }

    /**
     * Test that ping returns 404 for non-existent tenant UUID.
     */
    public function test_it_returns_404_for_invalid_tenant_uuid(): void
    {
        $response = $this->getJson('/api/00000000-0000-0000-0000-000000000000/ping');

        $response->assertStatus(404);
    }

    /**
     * Test that ping returns 404 for malformed UUID.
     */
    public function test_it_returns_404_for_malformed_uuid(): void
    {
        $response = $this->getJson('/api/not-a-valid-uuid/ping');

        $response->assertStatus(404);
    }
}
