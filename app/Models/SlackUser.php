<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SlackUser model
 *
 * Represents a Slack user within a tenant workspace.
 * Identity key is (tenant_id, slack_user_id) - unique per workspace.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $slack_user_id
 * @property string|null $real_name
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property bool $approved
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class SlackUser extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'slack_user_id', 'real_name', 'display_name', 'avatar_url', 'approved'];

    protected $casts = [
        'approved' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(SlackUserSetting::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }
}
