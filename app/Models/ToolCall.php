<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ToolCall model
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $query_id
 * @property string $tool
 * @property array $request_payload
 * @property array $response_payload
 * @property bool $is_completed
 * @property string|null $function_call_id
 * @property string|null $result_id
 * @property Tenant $tenant
 * @property Query $queryRecord
 * @property Carbon $anonymized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ToolCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'query_id',
        'tool',
        'request_payload',
        'response_payload',
        'is_completed',
        'function_call_id',
        'result_id',
        'anonymized_at',
    ];

    protected $casts = [
        'anonymized_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'is_completed' => 'bool',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function queryRecord(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }
}
