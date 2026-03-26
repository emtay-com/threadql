<?php

declare(strict_types=1);

namespace Feature\Admin\Tenant;

use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CreateTenantTest extends TestCase
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
        $response = $this->postJson('/api/admin/tenants', []);

        $response->assertStatus(401);
    }

    public function test_it_creates_a_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants', [
                'name' => 'New Tenant',
                'timezone' => 'America/New_York',
                'slack_app_id' => 'A123456',
                'slack_client_id' => 'C123456',
                'slack_bot_token' => 'xoxb-test-token',
                'slack_signing_secret' => 'signing-secret',
                'slack_verification_token' => 'verification-token',
            ]);

        $response->assertStatus(201);

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
        $this->assertEquals('New Tenant', $data['name']);
        $this->assertEquals('America/New_York', $data['timezone']);
        $this->assertArrayNotHasKey('llm_provider', $data);
        $this->assertEquals('A123456', $data['slack_app_id']);
        $this->assertEquals('C123456', $data['slack_client_id']);
        $this->assertTrue($data['has_slack_bot_token']);
        $this->assertTrue($data['has_slack_signing_secret']);
        $this->assertTrue($data['has_slack_verification_token']);

        $tenant = Tenant::find($data['id']);
        $this->assertNotNull($tenant);
        $this->assertEquals('New Tenant', $tenant->name);
        $this->assertEquals('xoxb-test-token', $tenant->slack_bot_token);
    }

    public function test_it_creates_a_tenant_with_only_name_and_timezone(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants', [
                'name' => 'Minimal Tenant',
                'timezone' => 'Europe/London',
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals('Minimal Tenant', $data['name']);
        $this->assertEquals('Europe/London', $data['timezone']);
        $this->assertFalse($data['has_slack_bot_token']);
        $this->assertFalse($data['has_slack_signing_secret']);
        $this->assertFalse($data['has_slack_verification_token']);
    }

    public function test_it_validates_required_fields(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'timezone']);
        $response->assertJsonMissingValidationErrors([
            'slack_app_id',
            'slack_client_id',
            'slack_bot_token',
            'slack_signing_secret',
            'slack_verification_token',
        ]);
    }

    public function test_it_validates_timezone(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants', [
                'name' => 'Test',
                'timezone' => 'Invalid/Timezone',
                'slack_app_id' => 'A123',
                'slack_client_id' => 'C123',
                'slack_bot_token' => 'token',
                'slack_signing_secret' => 'secret',
                'slack_verification_token' => 'verify',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }
}
