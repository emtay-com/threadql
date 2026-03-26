<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use Tests\TestCase;

class GeneralSettingTest extends TestCase
{
    public function test_setting_is_cast_to_enum(): void
    {
        $setting = GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
        ]);

        $setting->refresh();

        $this->assertInstanceOf(SettingEnum::class, $setting->setting);
        $this->assertEquals(SettingEnum::MAX_ROWS_INLINE_CSV, $setting->setting);
    }

    public function test_load_creates_setting_with_default_from_config(): void
    {
        $setting = GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV);

        $this->assertInstanceOf(GeneralSetting::class, $setting);
        $this->assertEquals(SettingEnum::MAX_ROWS_INLINE_CSV, $setting->setting);
        $this->assertEquals('1000', $setting->value);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_rows_inline_csv',
            'value' => '1000',
        ]);
    }

    public function test_load_creates_max_priority_tables_with_default(): void
    {
        $setting = GeneralSetting::resolve(SettingEnum::MAX_PRIORITY_TABLES);

        $this->assertEquals('20', $setting->value);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_priority_tables',
            'value' => '20',
        ]);
    }

    public function test_load_returns_existing_setting_without_overwriting(): void
    {
        $existing = GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '500',
        ]);

        $setting = GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV);

        $this->assertTrue($setting->is($existing));
        $this->assertEquals('500', $setting->value);
    }

    public function test_load_does_not_create_duplicate(): void
    {
        GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV);
        GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV);

        $this->assertCount(1, GeneralSetting::where('setting', SettingEnum::MAX_ROWS_INLINE_CSV->value)->get());
    }

    public function test_setting_has_unique_constraint(): void
    {
        GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
        ]);
    }

    public function test_value_is_nullable(): void
    {
        $setting = GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => null,
        ]);

        $setting->refresh();

        $this->assertNull($setting->value);
    }
}
