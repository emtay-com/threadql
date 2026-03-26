<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\TenantSetting;

use App\Enums\TenantSettingEnum;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateTenantSettingsTest extends TestCase
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

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/settings", [
            'settings' => [
                [
                    'name' => 'auto_approve_users',
                    'value' => '0',
                ],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_it_updates_settings_batch(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", [
                'settings' => [
                    [
                        'name' => 'auto_approve_users',
                        'value' => '0',
                    ],
                    [
                        'name' => 'user_rate_limit',
                        'value' => '10',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'auto_approve_users',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'user_rate_limit',
            'value' => '10',
        ]);
    }

    public function test_it_updates_existing_settings(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::USER_RATE_LIMIT,
            'value' => '5',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", [
                'settings' => [
                    [
                        'name' => 'user_rate_limit',
                        'value' => '25',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'user_rate_limit',
            'value' => '25',
        ]);
    }

    public function test_it_ignores_unknown_setting_names(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", [
                'settings' => [
                    [
                        'name' => 'nonexistent_setting',
                        'value' => 'foo',
                    ],
                    [
                        'name' => 'user_rate_limit',
                        'value' => '15',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('tenant_settings', [
            'name' => 'nonexistent_setting',
        ]);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'user_rate_limit',
            'value' => '15',
        ]);
    }

    public function test_it_validates_settings_is_required(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings']);
    }

    public function test_it_validates_settings_items_have_name_and_value(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", [
                'settings' => [
                    [
                        'value' => '5',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.0.name']);
    }

    public function test_it_validates_value_max_length(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/settings", [
                'settings' => [
                    [
                        'name' => 'user_rate_limit',
                        'value' => str_repeat('a', 256),
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.0.value']);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/tenants/99999/settings', [
                'settings' => [
                    [
                        'name' => 'user_rate_limit',
                        'value' => '10',
                    ],
                ],
            ]);

        $response->assertStatus(404);
    }
}
