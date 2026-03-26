<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GeneralSetting>
 */
class GeneralSettingFactory extends Factory
{
    protected $model = GeneralSetting::class;

    public function definition(): array
    {
        return [
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ];
    }
}
