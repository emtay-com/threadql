<?php

declare(strict_types=1);

namespace Feature\Admin\Tenant;

use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateTenantTest extends TestCase
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

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}", []);

        $response->assertStatus(401);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/tenants/99999', [
                'name' => 'Updated Name',
                'timezone' => 'UTC',
            ]);

        $response->assertStatus(404);
    }

    public function test_it_updates_tenant_name(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Original Name',
            'timezone' => 'UTC',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Updated Name',
                'timezone' => 'UTC',
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Updated Name', $data['name']);
        $this->assertEquals('UTC', $data['timezone']);

        $tenant->refresh();
        $this->assertEquals('Updated Name', $tenant->name);
    }

    public function test_it_updates_tenant_timezone(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Corp',
            'timezone' => 'UTC',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Test Corp',
                'timezone' => 'America/Los_Angeles',
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('America/Los_Angeles', $data['timezone']);

        $tenant->refresh();
        $this->assertEquals('America/Los_Angeles', $tenant->timezone);
    }

    public function test_it_updates_slack_tokens_when_provided(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Corp',
            'timezone' => 'UTC',
            'slack_bot_token' => 'old-token',
            'slack_signing_secret' => 'old-secret',
            'slack_verification_token' => 'old-verify',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Test Corp',
                'timezone' => 'UTC',
                'slack_bot_token' => 'new-token',
                'slack_signing_secret' => 'new-secret',
            ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertEquals('new-token', $tenant->slack_bot_token);
        $this->assertEquals('new-secret', $tenant->slack_signing_secret);
        $this->assertEquals('old-verify', $tenant->slack_verification_token);
    }

    public function test_it_does_not_update_slack_tokens_when_not_provided(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Original Name',
            'timezone' => 'UTC',
            'slack_bot_token' => 'original-token',
            'slack_signing_secret' => 'original-secret',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Updated Name',
                'timezone' => 'UTC',
            ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertEquals('Updated Name', $tenant->name);
        $this->assertEquals('original-token', $tenant->slack_bot_token);
        $this->assertEquals('original-secret', $tenant->slack_signing_secret);
    }

    public function test_it_validates_timezone(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Test',
                'timezone' => 'Invalid/Timezone',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    public function test_it_returns_full_tenant_payload(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Corp',
            'timezone' => 'UTC',
            'slack_app_id' => 'A123',
            'slack_client_id' => 'C123',
            'slack_bot_token' => 'token',
            'slack_signing_secret' => 'secret',
            'slack_verification_token' => 'verify',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}", [
                'name' => 'Updated Corp',
                'timezone' => 'UTC',
            ]);

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
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
        ]);

        $data = $response->json('data');
        $this->assertEquals('Updated Corp', $data['name']);
        $this->assertEquals($tenant->uuid, $data['uuid']);
        $this->assertTrue($data['has_slack_bot_token']);
    }
}
