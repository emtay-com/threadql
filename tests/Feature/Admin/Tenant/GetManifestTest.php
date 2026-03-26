<?php

declare(strict_types=1);

namespace Feature\Admin\Tenant;

use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class GetManifestTest extends TestCase
{
    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/manifest");

        $response->assertStatus(401);
    }

    /**
     * Test that the manifest endpoint returns valid JSON.
     */
    public function test_it_returns_manifest_for_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/manifest");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => ['manifest'],
        ]);

        // The manifest should be a JSON string
        $manifestJson = $response->json('data.manifest');
        $this->assertIsString($manifestJson);

        // Decode the manifest JSON to verify it's valid
        $manifest = json_decode($manifestJson, true);
        $this->assertIsArray($manifest);

        // Assert key properties exist in the manifest
        $this->assertArrayHasKey('display_information', $manifest);
        $this->assertArrayHasKey('features', $manifest);
        $this->assertArrayHasKey('oauth_config', $manifest);
        $this->assertArrayHasKey('settings', $manifest);

        // Assert tenant name is used as app name
        $this->assertEquals('Acme Corp', $manifest['display_information']['name']);
    }

    /**
     * Test that the manifest uses tenant name as bot display name when bot_name is null.
     */
    public function test_it_uses_tenant_name_when_bot_name_is_null(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Widget Inc',
            'bot_name' => null,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/manifest");

        $response->assertStatus(200);

        $manifestJson = $response->json('data.manifest');
        $manifest = json_decode($manifestJson, true);

        // Bot display name should fall back to tenant name
        $this->assertEquals('Widget Inc', $manifest['features']['bot_user']['display_name']);
    }

    /**
     * Test that the manifest uses custom bot_name when provided.
     */
    public function test_it_uses_bot_name_when_provided(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Widget Inc',
            'bot_name' => 'WidgetBot',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/manifest");

        $response->assertStatus(200);

        $manifestJson = $response->json('data.manifest');
        $manifest = json_decode($manifestJson, true);

        // Bot display name should use custom bot_name
        $this->assertEquals('WidgetBot', $manifest['features']['bot_user']['display_name']);
    }

    /**
     * Test that the manifest contains the tenant UUID in URLs.
     */
    public function test_it_contains_tenant_uuid_in_urls(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/manifest");

        $response->assertStatus(200);

        $manifestJson = $response->json('data.manifest');
        $manifest = json_decode($manifestJson, true);

        // URLs should contain the tenant UUID
        $eventsUrl = $manifest['settings']['event_subscriptions']['request_url'];
        $this->assertStringContainsString($tenant->uuid, $eventsUrl);

        $interactiveUrl = $manifest['settings']['interactivity']['request_url'];
        $this->assertStringContainsString($tenant->uuid, $interactiveUrl);
    }

    /**
     * Test that the endpoint returns 404 for non-existent tenant.
     */
    public function test_it_returns_404_for_non_existent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/manifest');

        $response->assertStatus(404);
    }
}
