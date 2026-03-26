<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantSettingEnum;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    protected $model = TenantSetting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ];
    }
}
