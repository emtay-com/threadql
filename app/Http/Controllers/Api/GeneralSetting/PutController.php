<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GeneralSetting;

use App\Enums\SettingEnum;
use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PutController extends Controller
{
    private const START_OF_WEEK_VALUES = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    private const WEEK_DEFINITION_VALUES = ['iso', 'us'];

    /**
     * Batch update general settings.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.setting' => 'required|string',
            'settings.*.value' => 'required|string|max:255',
        ]);

        foreach ($validated['settings'] as $index => $settingData) {
            $enum = SettingEnum::tryFrom($settingData['setting']);
            if ($enum === null) {
                continue;
            }

            $value = $this->normalizeSettingValue($enum, (string) $settingData['value']);
            $this->assertValidValue($enum, $value, $index);

            $setting = GeneralSetting::resolve($enum);
            $setting->value = $value;
            $setting->save();
        }

        return response()->noContent();
    }

    private function normalizeSettingValue(SettingEnum $setting, string $value): string
    {
        $normalized = trim($value);

        return match ($setting) {
            SettingEnum::START_OF_WEEK, SettingEnum::WEEK_DEFINITION => strtolower($normalized),
            default => $normalized,
        };
    }

    private function assertValidValue(SettingEnum $setting, string $value, int $index): void
    {
        if ($this->isNumericSetting($setting) && ! preg_match('/^\d+$/', $value)) {
            throw ValidationException::withMessages([
                "settings.{$index}.value" => "The {$setting->value} setting must be a whole number.",
            ]);
        }

        if ($setting === SettingEnum::START_OF_WEEK && ! in_array($value, self::START_OF_WEEK_VALUES, true)) {
            throw ValidationException::withMessages([
                "settings.{$index}.value" => 'The start_of_week setting must be a valid weekday.',
            ]);
        }

        if ($setting === SettingEnum::WEEK_DEFINITION && ! in_array($value, self::WEEK_DEFINITION_VALUES, true)) {
            throw ValidationException::withMessages([
                "settings.{$index}.value" => 'The week_definition setting must be one of: iso, us.',
            ]);
        }
    }

    private function isNumericSetting(SettingEnum $setting): bool
    {
        return in_array($setting, [
            SettingEnum::MAX_ROWS_INLINE_CSV,
            SettingEnum::MAX_PRIORITY_TABLES,
            SettingEnum::LLM_RESUME_MAX_STEPS,
            SettingEnum::MAX_TOKENS,
        ], true);
    }
}
