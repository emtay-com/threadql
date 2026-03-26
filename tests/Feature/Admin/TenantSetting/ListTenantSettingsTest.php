<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\TenantSetting;

use App\Enums\TenantSettingEnum;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListTenantSettingsTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/settings");

        $response->assertStatus(401);
    }

    public function test_it_lists_all_settings_with_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/settings");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'tenant_id', 'name', 'value', 'created_at'],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(count(TenantSettingEnum::cases()), $data);

        $names = array_column($data, 'name');
        foreach (TenantSettingEnum::cases() as $case) {
            $this->assertContains($case->value, $names);
        }

        $this->assertEquals(count(TenantSettingEnum::cases()), $response->json('meta.total'));
    }

    public function test_it_creates_missing_settings_with_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertDatabaseMissing('tenant_settings', [
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/settings");

        foreach (TenantSettingEnum::cases() as $case) {
            $this->assertDatabaseHas('tenant_settings', [
                'tenant_id' => $tenant->id,
                'name' => $case->value,
            ]);
        }
    }

    public function test_it_returns_existing_settings_without_overwriting(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::USER_RATE_LIMIT,
            'value' => '20',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/settings");

        $response->assertStatus(200);

        $data = $response->json('data');
        $rateLimitSetting = collect($data)
            ->firstWhere('name', 'user_rate_limit');
        $this->assertEquals('20', $rateLimitSetting['value']);
    }

    public function test_it_returns_default_values_for_new_settings(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/settings");

        $data = $response->json('data');

        $autoApprove = collect($data)
            ->firstWhere('name', 'auto_approve_users');
        $this->assertEquals('1', $autoApprove['value']);

        $rateLimit = collect($data)
            ->firstWhere('name', 'user_rate_limit');
        $this->assertEquals('5', $rateLimit['value']);
    }

    public function test_it_scopes_settings_to_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        TenantSetting::factory()->create([
            'tenant_id' => $tenant1->id,
            'name' => TenantSettingEnum::USER_RATE_LIMIT,
            'value' => '10',
        ]);

        TenantSetting::factory()->create([
            'tenant_id' => $tenant2->id,
            'name' => TenantSettingEnum::USER_RATE_LIMIT,
            'value' => '50',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/settings");
        $data1 = $response1->json('data');
        $rateLimit1 = collect($data1)
            ->firstWhere('name', 'user_rate_limit');
        $this->assertEquals('10', $rateLimit1['value']);

        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant2->id}/settings");
        $data2 = $response2->json('data');
        $rateLimit2 = collect($data2)
            ->firstWhere('name', 'user_rate_limit');
        $this->assertEquals('50', $rateLimit2['value']);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/settings');

        $response->assertStatus(404);
    }
}
