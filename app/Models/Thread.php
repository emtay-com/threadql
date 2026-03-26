<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThreadStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Thread model
 *
 * Represents a Slack thread that we interact with.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $team_id
 * @property string $channel_id
 * @property string $thread_ts
 * @property string|null $starter_user_id
 * @property string $status
 * @property string|null $last_message_ts
 * @property int|null $slack_user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, Query> $queries
 * @property-read SlackUser|null $slackUser
 */
class Thread extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'threads';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'team_id',
        'channel_id',
        'thread_ts',
        'starter_user_id',
        'status',
        'last_message_ts',
        'slack_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Allowed status values
     */
    public const STATUS_ACTIVE = ThreadStatus::ACTIVE->value;

    public const STATUS_ARCHIVED = ThreadStatus::ARCHIVED->value;

    public const STATUS_CLOSED = ThreadStatus::CLOSED->value;

    /**
     * Get the tenant that owns this thread.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the Slack user that owns this thread.
     */
    public function slackUser(): BelongsTo
    {
        return $this->belongsTo(SlackUser::class);
    }

    /**
     * Get the queries for this thread.
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }
}
