<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SlackUserSetting model
 *
 * Stores per-workspace user preferences/settings.
 * Unique constraint on (slack_user_id, key).
 *
 * @property int $id
 * @property int $slack_user_id
 * @property string $key
 * @property string $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SlackUserSetting extends Model
{
    use HasFactory;

    protected $fillable = ['slack_user_id', 'key', 'value'];

    public function slackUser(): BelongsTo
    {
        return $this->belongsTo(SlackUser::class);
    }

    /**
     * Check if the setting value indicates "on" (enabled)
     */
    public function isOn(): bool
    {
        return $this->value === 'on';
    }

    public function isOff(): bool
    {
        return $this->value === 'off';
    }
}
