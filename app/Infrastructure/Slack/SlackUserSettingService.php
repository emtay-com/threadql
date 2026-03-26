<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

use App\Contracts\SettingConstants;
use App\Enums\Settings;
use App\Models\SlackUser;
use App\Models\SlackUserSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing per-workspace user settings.
 *
 * Handles setting lookup with fallback to config defaults,
 * and upserting settings with caching.
 */
class SlackUserSettingService
{
    /**
     * Check if a setting is enabled for a user.
     *
     * @param SlackUser $user The Slack user
     * @param Settings|string $setting The setting key (enum or string)
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled(SlackUser $user, Settings|string $setting): bool
    {
        $settingKey = $this->normalizeSettingKey($setting);

        $dbSetting = SlackUserSetting::where('slack_user_id', $user->id)
            ->where('key', $settingKey)
            ->first();

        if ($dbSetting) {
            return $dbSetting->isOn();
        }

        // Fallback to config default
        return (bool) config("slack-settings.defaults.{$settingKey}", false);

    }

    /**
     * Set a setting for a user.
     *
     * @param SlackUser $user The Slack user
     * @param Settings|string $setting The setting key (enum or string)
     * @param bool $isEnabled Whether to enable the setting
     */
    public function setEnabled(SlackUser $user, Settings|string $setting, bool $isEnabled): void
    {
        $settingKey = $this->normalizeSettingKey($setting);
        $value = $isEnabled ? SettingConstants::ON : SettingConstants::OFF;

        SlackUserSetting::updateOrCreate(
            [
                'slack_user_id' => $user->id,
                'key' => $settingKey,
            ],
            [
                'value' => $value,
            ]
        );

        // Clear cache for this setting
        $cacheKey = "slack_user_setting:{$user->id}:{$settingKey}";
        Cache::forget($cacheKey);
    }

    /**
     * Normalize setting key to string.
     */
    private function normalizeSettingKey(Settings|string $setting): string
    {
        return $setting instanceof Settings ? $setting->value : $setting;
    }
}
