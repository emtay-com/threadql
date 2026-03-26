<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Payloads;

use App\Http\Payloads\TenantPayload;
use App\Models\Tenant;
use Tests\TestCase;

class TenantPayloadTest extends TestCase
{
    public function test_it_serializes_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
            'timezone' => 'America/New_York',
            'slack_app_id' => 'A123',
            'slack_client_id' => 'C456',
            'slack_bot_token' => 'xoxb-token',
            'slack_signing_secret' => 'secret',
            'slack_verification_token' => 'verify',
        ]);

        $payload = new TenantPayload($tenant);
        $result = $payload->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $data = $result['data'];

        $this->assertEquals($tenant->id, $data['id']);
        $this->assertEquals('Acme Corp', $data['name']);
        $this->assertEquals($tenant->uuid, $data['uuid']);
        $this->assertEquals('America/New_York', $data['timezone']);
        $this->assertArrayNotHasKey('llm_provider', $data);
        $this->assertEquals('A123', $data['slack_app_id']);
        $this->assertEquals('C456', $data['slack_client_id']);
        $this->assertTrue($data['has_slack_bot_token']);
        $this->assertTrue($data['has_slack_signing_secret']);
        $this->assertTrue($data['has_slack_verification_token']);
        $this->assertNotNull($data['created_at']);
        $this->assertNotNull($data['updated_at']);
    }

    public function test_it_serializes_tenant_without_slack_credentials(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Widget Inc',
            'timezone' => 'UTC',
            'slack_app_id' => null,
            'slack_client_id' => null,
            'slack_bot_token' => null,
            'slack_signing_secret' => null,
            'slack_verification_token' => null,
        ]);

        $payload = new TenantPayload($tenant);
        $result = $payload->jsonSerialize();

        $data = $result['data'];

        $this->assertEquals($tenant->id, $data['id']);
        $this->assertEquals('Widget Inc', $data['name']);
        $this->assertFalse($data['has_slack_bot_token']);
        $this->assertFalse($data['has_slack_signing_secret']);
        $this->assertFalse($data['has_slack_verification_token']);
    }

    public function test_to_array_returns_flat_data_without_wrapper(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Corp',
            'timezone' => 'Europe/London',
            'slack_bot_token' => 'token',
            'slack_signing_secret' => null,
            'slack_verification_token' => null,
        ]);

        $payload = new TenantPayload($tenant);
        $result = $payload->toArray();

        $this->assertArrayNotHasKey('data', $result);
        $this->assertEquals($tenant->id, $result['id']);
        $this->assertEquals('Test Corp', $result['name']);
        $this->assertTrue($result['has_slack_bot_token']);
        $this->assertFalse($result['has_slack_signing_secret']);
    }
}
