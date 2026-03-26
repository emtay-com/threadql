<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * GeneralSetting model
 *
 * Represents a global application setting.
 *
 * @property int $id
 * @property SettingEnum $setting
 * @property string|null $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class GeneralSetting extends Model
{
    use HasFactory;

    protected $table = 'general_settings';

    protected $fillable = ['setting', 'value'];

    protected $casts = [
        'setting' => SettingEnum::class,
    ];

    /**
     * Resolve a setting by enum, creating it with the default value from config if it doesn't exist.
     */
    public static function resolve(SettingEnum $setting): self
    {
        $existing = self::where('setting', $setting->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $defaultValue = config("default_settings.{$setting->value}");

        return self::create([
            'setting' => $setting,
            'value' => $defaultValue,
        ]);
    }
}
