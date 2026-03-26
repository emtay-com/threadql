<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TenantSettingEnum;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Tests\TestCase;

class TenantSettingTest extends TestCase
{
    public function test_it_belongs_to_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue($setting->tenant->is($tenant));
    }

    public function test_name_is_cast_to_enum(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
        ]);

        $setting->refresh();

        $this->assertInstanceOf(TenantSettingEnum::class, $setting->name);
        $this->assertEquals(TenantSettingEnum::AUTO_APPROVE_USERS, $setting->name);
    }

    public function test_is_enabled_returns_true_when_value_is_1(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'value' => '1',
        ]);

        $this->assertTrue($setting->isEnabled());
    }

    public function test_is_enabled_returns_false_when_value_is_not_1(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'value' => '0',
        ]);

        $this->assertFalse($setting->isEnabled());
    }

    public function test_is_enabled_returns_false_for_arbitrary_string(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'value' => '5',
        ]);

        $this->assertFalse($setting->isEnabled());
    }

    public function test_set_enabled_sets_value_to_1(): void
    {
        $tenant = Tenant::factory()->create();
        $setting = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'value' => '0',
        ]);

        $setting->setEnabled();

        $this->assertEquals('1', $setting->value);
        $this->assertTrue($setting->isEnabled());
    }

    public function test_tenant_get_setting_creates_setting_with_default_value(): void
    {
        $tenant = Tenant::factory()->create();

        $setting = $tenant->getSetting(TenantSettingEnum::AUTO_APPROVE_USERS);

        $this->assertInstanceOf(TenantSetting::class, $setting);
        $this->assertEquals(TenantSettingEnum::AUTO_APPROVE_USERS, $setting->name);
        $this->assertEquals('1', $setting->value);
        $this->assertTrue($setting->isEnabled());
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'auto_approve_users',
            'value' => '1',
        ]);
    }

    public function test_tenant_get_setting_creates_rate_limit_with_default_value(): void
    {
        $tenant = Tenant::factory()->create();

        $setting = $tenant->getSetting(TenantSettingEnum::USER_RATE_LIMIT);

        $this->assertEquals('5', $setting->value);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'name' => 'user_rate_limit',
            'value' => '5',
        ]);
    }

    public function test_tenant_get_setting_returns_existing_setting(): void
    {
        $tenant = Tenant::factory()->create();
        $existing = TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::AUTO_APPROVE_USERS);

        $this->assertTrue($setting->is($existing));
        $this->assertEquals('0', $setting->value);
        $this->assertFalse($setting->isEnabled());
    }

    public function test_tenant_settings_relationship(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
        ]);
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::USER_RATE_LIMIT,
            'value' => '10',
        ]);

        $this->assertCount(2, $tenant->settings);
    }

    public function test_tenant_setting_has_unique_constraint_on_tenant_and_name(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
        ]);
    }
}
