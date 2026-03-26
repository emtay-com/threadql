<?php

declare(strict_types=1);

namespace Feature\Admin\Tenant;

use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListTenantsTest extends TestCase
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
        $response = $this->getJson('/api/admin/tenants');

        $response->assertStatus(401);
    }

    public function test_it_lists_all_tenants(): void
    {
        $tenant1 = Tenant::factory()->create([
            'name' => 'Acme Corp',
            'timezone' => 'America/New_York',
        ]);

        $tenant2 = Tenant::factory()->create([
            'name' => 'Widget Inc',
            'timezone' => 'America/Los_Angeles',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'uuid',
                    'timezone',
                    'slack_app_id',
                    'slack_client_id',
                    'has_slack_bot_token',
                    'has_slack_signing_secret',
                    'has_slack_verification_token',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $tenant1Data = collect($data)
            ->firstWhere('id', $tenant1->id);
        $this->assertNotNull($tenant1Data);
        $this->assertEquals('Acme Corp', $tenant1Data['name']);
        $this->assertEquals('America/New_York', $tenant1Data['timezone']);
        $this->assertArrayNotHasKey('llm_provider', $tenant1Data);

        $tenant2Data = collect($data)
            ->firstWhere('id', $tenant2->id);
        $this->assertNotNull($tenant2Data);
        $this->assertEquals('Widget Inc', $tenant2Data['name']);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_it_does_not_expose_secrets(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Secret Corp',
            'slack_bot_token' => 'xoxb-secret-token',
            'slack_signing_secret' => 'secret-signing',
            'slack_verification_token' => 'verification-token',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants');

        $response->assertStatus(200);

        $data = $response->json('data');
        $tenantData = collect($data)
            ->firstWhere('id', $tenant->id);

        $this->assertArrayNotHasKey('slack_bot_token', $tenantData);
        $this->assertArrayNotHasKey('slack_signing_secret', $tenantData);
        $this->assertArrayNotHasKey('slack_verification_token', $tenantData);

        $this->assertTrue($tenantData['has_slack_bot_token']);
        $this->assertTrue($tenantData['has_slack_signing_secret']);
        $this->assertTrue($tenantData['has_slack_verification_token']);
    }

    public function test_it_orders_tenants_by_created_at_desc(): void
    {
        $older = Tenant::factory()->create([
            'name' => 'Older Corp',
        ]);
        $this->travel(1)
            ->hours();
        $newer = Tenant::factory()->create([
            'name' => 'Newer Corp',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals($newer->id, $data[0]['id']);
        $this->assertEquals($older->id, $data[1]['id']);
    }
}
