<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueryStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Query model
 *
 * Represents a natural language query and its execution
 * results within a tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $thread_id
 * @property string|null $slack_event_id
 * @property string|null $channel_id
 * @property string|null $message_ts
 * @property string $status
 * @property string|null $user_id
 * @property string $raw_text
 * @property array|null $plan_json
 * @property string|null $sql_text
 * @property string|null $outcome
 * @property array|null $parameters
 * @property array|null $result_meta_json
 * @property int|null $latency_ms
 * @property int $score
 * @property int|null $slack_user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Thread $thread
 * @property-read Collection<int, Feedback> $feedback
 * @property-read SlackUser|null $slackUser
 */
class Query extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'queries';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'thread_id',
        'slack_event_id',
        'channel_id',
        'message_ts',
        'status',
        'user_id',
        'raw_text',
        'plan_json',
        'sql_text',
        'outcome',
        'parameters',
        'result_meta_json',
        'latency_ms',
        'score',
        'slack_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'plan_json' => 'array',
        'result_meta_json' => 'array',
        'latency_ms' => 'integer',
        'score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'parameters' => 'array',
    ];

    /**
     * Allowed status values
     */
    public const STATUS_RECEIVED = QueryStatus::RECEIVED->value;

    public const STATUS_PLANNING = QueryStatus::PLANNING->value;

    public const STATUS_EXECUTING = QueryStatus::EXECUTING->value;

    public const STATUS_INPUT_REQUESTED = QueryStatus::INPUT_REQUESTED->value;

    public const STATUS_ERROR = QueryStatus::ERROR->value;

    public const STATUS_DONE = QueryStatus::DONE->value;

    /**
     * Get the tenant that owns this query.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the thread that owns this query.
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * Get the Slack user that owns this query.
     */
    public function slackUser(): BelongsTo
    {
        return $this->belongsTo(SlackUser::class);
    }

    /**
     * Get the feedback for this query.
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Get the tool calls for this query.
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
